<?php

function call_r(array &$lines, Closure $closure, ?object $parent = null)
{
    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];

        foreach (array_keys(get_object_vars($line)) as $prop) {
            if (is_array($line->$prop)) {
                call_r($line->$prop, $closure, $line);

                if (!$line->$prop) {
                    unset($line->$prop);
                }
            }
        }

        $add = [];
        $remove = (int) !($closure($line, $parent, $add) ?? true);

        array_splice($lines, $i, $remove, $add);

        $i += count($add) - $remove;
    }
}

function config_valid(object $config)
{
    $errorcount = 0;

    if (!@$config->master_log) {
        error_log("No master_log given");
        $errorcount++;
    } elseif (!file_exists($config->master_log)) {
        error_log("Master log file does not exist");
        $errorcount++;
    }

    if (!$username = @$config->username) {
        error_log("Please specify username in config");
        $errorcount++;
    }

    if (!@$config->portal_class) {
        error_log("Please specify portal_class in config");
        $errorcount++;
    }

    if (!@$config->jars_bin) {
        error_log("Please specify jars_bin in config");
        $errorcount++;
    }

    if (!@$config->autoload) {
        error_log("Please specify autoload in config");
        $errorcount++;
    }

    if (@$config->old_n2h && !is_callable($config->old_n2h)) {
        error_log("Config old_n2h should be callable");
        $errorcount++;
    }

    if (@$config->rewrite && !is_callable($config->rewrite)) {
        error_log("Config rewrite should be callable");
        $errorcount++;
    }

    if (@$config->id_offsetting && !is_bool($config->id_offsetting)) {
        error_log("Config id_offsetting should be boolean");
        $errorcount++;
    }

    return $errorcount === 0;
}

function id_map_r(array $data, array $incoming_links, array $exclude)
{
    global $id_map;

    foreach ($data as $line) {
        if (!$type = $line->type) {
            error_log(json_encode($line));
            error_log('Could not determine type');
        }

        if (@$line->id) {
            $orig = $line->id;
            $line->id = @$id_map[$line->id] ?? $line->id;

            if ($orig !== $line->id) {
                log_fineprint("Mapped $orig to {$line->id}");
            }
        }

        if ($orig = @$line->user) {
            $line->user = @$id_map[$line->user] ?? $line->user;

            if ($orig !== $line->user) {
                log_fineprint("Mapped $orig to {$line->user} (user)");
            }
        }

        foreach (get_object_vars($line) as $key => $value) {
            if (preg_match('/_id$/', $key) && !in_array($key, $exclude)) {
                $orig = $line->$key;
                $line->$key = @$id_map[$line->$key] ?? $line->$key;

                if ($orig !== $line->$key) {
                    log_fineprint("Mapped $orig to {$line->$key}");
                }
            }
        }

        if (@$line->_adopt) {
            foreach ($line->_adopt as $set => &$children) {
                foreach ($children as $i => $child_id) {
                    $orig = $children[$i];
                    $children[$i] = @$id_map[$child_id] ?? $child_id;

                    if ($orig !== $children[$i]) {
                        log_fineprint("Mapped $orig to {$children[$i]}");
                    }
                }
            }
        }

        if (@$line->_disown) {
            foreach ($line->_disown as $set => &$children) {
                foreach ($children as $i => $child_id) {
                    $orig = $children[$i];
                    $children[$i] = @$id_map[$child_id] ?? $child_id;

                    if ($orig !== $children[$i]) {
                        log_fineprint("Mapped $orig to {$children[$i]}");
                    }
                }
            }
        }

        foreach (array_keys(get_object_vars($line)) as $key) {
            if (is_array($line->$key)) {
                id_map_r($line->$key, $incoming_links, $exclude);
            }
        }

        foreach ($incoming_links[$type] ?? [] as $old_alias => $new_alias) {
            if ($orig = @$line->$old_alias) {
                $line->$new_alias = @$id_map[$line->$old_alias] ?? $line->$old_alias;

                if ($orig !== $line->$new_alias) {
                    log_fineprint("Mapped $orig to {$line->$new_alias}");
                }
            }
        }
    }
}

function jars_command(string $suffix, object $config, bool $masked = false)
{
    if ($env = implode(' ', array_map(fn ($key) => $key . "='" . ($masked ? '***' : escapeshellarg($config->env[$key])) . "'", array_keys($config->env ?? [])))) {
        $env .= ' ';
    }

    $password = $masked ? '***' : $config->password;

    $base_cmd = $env . "'$config->jars_bin' '--autoload=$config->autoload' '--connection-string=local:$config->portal_class,$config->db_home' -u '$config->username' -p '$password'";

    return $base_cmd . ' ' . $suffix;
}

function log_error($message) {
    message($message, "\033[31m");
}

function log_feedback($message) {
    message($message, "\033[33m");
}

function log_fineprint($message) {
    message($message, "\033[90m");
}

function log_output($message) {
    message($message, "\033[32m");
}

function message($message, string $color)
{
    echo $color;
    echo $message . "\n";
    echo "\033[39m";
}

function n2h_old($n)
{
    global $config;

    if (!@$config->old_secret) {
        return null;
    }

    return hash('sha256', hash('sha256', $n . '--' . $config->old_secret));
}

function read_password()
{
    $f = popen("/bin/bash -c 'read -s; echo \$REPLY'", "r");
    $input = fgets($f, 100);
    pclose($f);
    echo "\n";

    return preg_replace('/\n$/', '', $input);
}

function rewrite_seal(int $num = 1)
{
    global $id_offset, $config;

    if (!@$config->id_offsetting) {
        die("To call rewrite_seal, you must enable id_offsetting in config\n");
    }

    $id_offset -= $num;
}

function rewrite_prise(int $num = 1)
{
    global $id_offset, $config;

    if (!@$config->id_offsetting) {
        die("To call rewrite_prise, you must enable id_offsetting in config\n");
    }

    $id_offset += $num;
}

class VariableStream {
    var $position;
    var $varname;
    var $context;

    function stream_stat()
    {
        return [];
    }

    function stream_open($path, $mode, $options, &$opened_path)
    {
        $url = parse_url($path);
        $this->varname = $url["host"];
        $this->position = 0;

        return true;
    }

    function stream_read($count)
    {
        $ret = substr($GLOBALS[$this->varname], $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    function stream_write($data)
    {
        $left = substr($GLOBALS[$this->varname], 0, $this->position);
        $right = substr($GLOBALS[$this->varname], $this->position + strlen($data));
        $GLOBALS[$this->varname] = $left . $data . $right;
        $this->position += strlen($data);
        return strlen($data);
    }

    function stream_tell()
    {
        return $this->position;
    }

    function stream_eof()
    {
        return $this->position >= strlen($GLOBALS[$this->varname]);
    }

    function stream_seek($offset, $whence)
    {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($GLOBALS[$this->varname]) && $offset >= 0) {
                     $this->position = $offset;
                     return true;
                } else {
                     return false;
                }
                break;

            case SEEK_CUR:
                if ($offset >= 0) {
                     $this->position += $offset;
                     return true;
                } else {
                     return false;
                }
                break;

            case SEEK_END:
                if (strlen($GLOBALS[$this->varname]) + $offset >= 0) {
                     $this->position = strlen($GLOBALS[$this->varname]) + $offset;
                     return true;
                } else {
                     return false;
                }
                break;

            default:
                return false;
        }
    }

    function stream_metadata($path, $option, $var)
    {
        if($option == STREAM_META_TOUCH) {
            $url = parse_url($path);
            $varname = $url["host"];
            if(!isset($GLOBALS[$varname])) {
                $GLOBALS[$varname] = '';
            }
            return true;
        }
        return false;
    }
}

stream_wrapper_register("var", "VariableStream") or die("Failed to register protocol");
