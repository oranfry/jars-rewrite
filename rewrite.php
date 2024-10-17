<?php

require __DIR__ . '/lib.php';

$config = [];

if (file_exists($global_file = __DIR__ . '/configs/_global.php')) {
    $config = require $global_file;

    if (!is_array($config)) {
        error_log("Global config must return an array");

        exit(1);
    }
}

if ($config_file = @$argv[1]) {
    if (!preg_match(',^/,', $config_file)) {
        $config_file = __DIR__ . '/configs/' . $config_file . '.php';
    }

    if (!file_exists($config_file)) {
        error_log("Config file [$config_file] does not exist");

        exit(1);
    }

    if (!is_array($_local = require $config_file)) {
        error_log("Local config must return an array");

        exit(1);
    }

    $config = $_local + $config;
}

$config = (object) $config;

if ($config_file = @$config->portal_config_file) {
    if (!file_exists($config_file)) {
        error_log("Portal config file [$config_file] does not exist");

        exit(1);
    }

    if (!is_object($_portal = json_decode(file_get_contents($config_file)))) {
        error_log("Portal config is expected to be an object");

        exit(1);
    }

    if (@$_portal->connection_string) {
        if (!preg_match('/^local:(.*),(.*)/', $_portal->connection_string, $matches)) {
            error_log("Portal config connection_string should be a valid local connection string");

            exit(1);
        }

        if (!@$config->portal_class && !@$config->db_home) {
            $config->portal_class = $matches[1];
            $config->db_home = $matches[2];
        }
    }

    if (@$_portal->autoload && !@$config->autoload) {
        $config->autoload = $_portal->autoload;
    }

    if (@$_portal->username && !@$config->username) {
        $config->username = $_portal->username;
    }
}

if (!config_valid($config)) {
    error_log('Invalid config');

    exit(1);
}

$config->db_home ??= __DIR__ . '/out';

if (!@$config->password) {
    echo "Password: ";

    $config->password = read_password();
}

$fifo = stream_get_meta_data(tmpfile())['uri'];

@unlink($fifo); // just in case gc is too slow

if (!posix_mkfifo($fifo, 0600)) {
    throw new Exception("Could not create the temporary FIFO");
}

echo "FIFO: $fifo\n";

shell_exec('rm -rf "' . $config->db_home . '"; mkdir "' . $config->db_home . '";');

$import_cmd = "import '$fifo'";
$refresh_cmd = 'refresh';

echo "Importing...\n" . jars_command($import_cmd, $config, true) . "\n";

$jars_process = proc_open(jars_command($import_cmd, $config), [
   0 => ['pipe', 'r'],
   1 => ['pipe', 'w'],
   2 => ['pipe', 'w'],
], $pipes, '/tmp');

if (!proc_get_status($jars_process)['running']) {
    error_log('Oops, looks like we could not start the process');

    exit(1);
}

[$jars_input, $jars_output, $jars_error] = $pipes;

unset($pipes);

if (false == $master_log_handle = fopen($config->master_log, 'r')) {
    error_log('Could not start jars process');

    exit(1);
}

log_fineprint("Opening feedback fifo for READ...");

$feedback_handle = fopen($fifo, 'r');

log_fineprint("Successfully opened feedback fifo for READ.");

stream_set_blocking($feedback_handle, false);
stream_set_blocking($jars_error, false);
stream_set_blocking($jars_output, false);

$old_pointer = 0;
$id_map = [];
$failed = false;
$fakelog = [];
$id_offset = 0;

while (false !== $log_entry = array_pop($fakelog) ?: fgets($master_log_handle)) {
    $result = preg_match('/^([0-9a-f]{64}) ([0-9-]+) ([0-9:]+) (\[.*\])/', $log_entry, $matches);

    if (!$result) {
        error_log("Unrecognised line format");
        log_fineprint(substr($log_entry, 0, 200));

        exit(1);
    }

    [, $hash, $date, $time, $json] = $matches;

    $data = json_decode($json);

    if ($closure = $config->rewrite ?? null) {
        call_r($data, $closure);
    }

    if (!count($data)) {
        log_fineprint('skipping entry ' . $date . ' ' . $time);

        continue;
    }

    id_map_r($data, $config->incoming_links ?? [], $config->mapping_exclude ?? []);

    $translated = hash('sha256', '') . ' ' . $date . ' ' . $time . ' ' . json_encode($data);

    log_fineprint(substr(trim($translated, "\n"), 0, 150) . (strlen($log_entry) > 150 ? '...' : ''));

    fwrite($jars_input, $translated . "\n");

    for ($finished = false; !$finished; usleep(100)) {
        $feedback = trim(fgets($feedback_handle), "\n");
        $error = trim(fgets($jars_error), "\n");
        $output = trim(fgets($jars_output), "\n");

        if ($output) {
            log_output($output);
        }

        if ($error) {
            log_error($error);
        }

        if ($feedback) {
            log_feedback($feedback);

            if (preg_match('/^issued: (\S+) (\S+)/', $feedback, $matches)) {
                [, $pointer, $id] = $matches;

                $old_pointer++;
                $old_id = null;

                if (!@$config->id_offsetting) {
                    $display_old_pointer = $old_pointer;
                    $old_id = @$config->old_n2h ? ($config->old_n2h)($old_pointer): n2h_old($old_pointer);
                } elseif ($id_offset) {
                    $display_old_pointer = $pointer - $id_offset;
                    $old_id = trim(shell_exec(jars_command('n2h ' . $display_old_pointer, $config)));
                }

                if ($old_id) {
                    $id_map[$old_id] = $id;
                    $id_map["new:$id"] = $old_id;

                    log_fineprint("Saved map $old_id → $id ($display_old_pointer → $pointer)");
                }

                if ($callable = $config->after_issue ?? null) {
                    $_fakelog = [];
                    $callable($pointer, $id, $old_pointer, $old_id, $date . ' ' . $time, $_fakelog);
                    $fakelog = array_merge($fakelog, $_fakelog);
                }
            } elseif ($feedback === 'entry imported') {
                $finished = true;
            }
        }

        if (!proc_get_status($jars_process)['running']) {
            error_log('Oops, look as though the process died');
            $failed = true;
            break 2;
        }
    }
}

fclose($master_log_handle);
fclose($jars_input);

stream_set_blocking($jars_error, true);
stream_set_blocking($feedback_handle, true);
stream_set_blocking($jars_output, true);

log_error(stream_get_contents($jars_error));
log_output(stream_get_contents($jars_output));
log_feedback(stream_get_contents($feedback_handle));

fclose($feedback_handle);
fclose($jars_output);
fclose($jars_error);

proc_close($jars_process);

if (!$failed && $map_file = @$config->id_map_file) {
    file_put_contents($map_file, json_encode($id_map, JSON_UNESCAPED_SLASHES));
}

if ($config->refresh ?? true) {
    echo "Refreshing...\n" . jars_command($refresh_cmd, $config, true) . "\n";
    shell_exec(jars_command($refresh_cmd, $config));
}