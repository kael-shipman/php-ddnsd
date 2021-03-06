<?php
namespace KS\Ddnsd;
declare(ticks = 1);

class DdnsDaemon extends \KS\AbstractDaemon
{
    private $mark;
    private $lastIpCheck;
    private $registeredIps = [];

    public function __construct(\KS\DaemonConfigInterface $config)
    {
        if (!($config instanceof DdnsDaemonConfigInterface)) {
            throw new \InvalidArgumentException("Passed \$config object must be an instance of DdnsDaemonConfigInterface. ".get_class($config)." given.");
        }
        parent::__construct($config);
    }

    public function run()
    {
        $this->log("Starting ddnsd", LOG_INFO, [ "syslog", STDERR ], true);
        $this->mark = time()-($this->config->getCheckInterval()+1);
        try {
            do {
                if ($this->mark < time()-$this->config->getCheckInterval()) {
                    $this->mark = time();

                    try {
                        foreach($this->config->getProfiles() as $domain => $p) {
                            foreach($p['subdomains'] as $subdomain) {
                                if ($subdomain === '@') {
                                    $hostname = $domain;
                                } else {
                                    $hostname = "$subdomain.$domain";
                                }

                                // If our IP has changed, go change all our records
                                if ($this->getRegisteredIp($hostname) !== $this->getSelfIp()) {
                                    $this->log("IP changed. Updating records.", LOG_INFO);
                                    $config = $p;
                                    unset($config['provider'], $config['credentials']);
                                    $config['ip'] = $this->getSelfIp();
                                    $config['domain'] = $domain;
                                    $config = str_replace(["'"], ["'\"'\"'"], json_encode($config));

                                    putenv("DDNSD_CREDENTIALS=".str_replace(["'"], ["'\"'\"'"], $p['credentials']));
                                    $command = $this->config->getProviderPrefix().$p['provider']." change-ip '$config'";

                                    $this->log("Config: $config", LOG_DEBUG);
                                    $this->log("Credentials: DDNSD_CREDENTIALS=".str_replace(["'"], ["'\"'\"'"], $p['credentials']), LOG_DEBUG);
                                    $this->log("Command: $command", LOG_DEBUG);

                                    for ($i = 0; $i < 3; $i++) {
                                        $this->log("Running provider command to update ip: $command", LOG_DEBUG);
                                        exec($command, $output, $returnVal);
                                        if ($returnVal == 0) {
                                            break;
                                        } else {
                                            sleep(5);
                                        }
                                    }
                                    putenv("DDNSD_CREDENTIALS");

                                    if ($returnVal > 0) {
                                        $this->log("Couldn't execute provider sub-command!", LOG_ERR, [ "syslog", STDERR ]);
                                    } else {
                                        $this->log("Provider command successful!", LOG_DEBUG);
                                    }

                                    unset($config, $command, $output, $returnVal);
                                } else {
                                    $this->log("IP hasn't changed. Not updating records.", LOG_INFO);
                                }
                            }
                        }
                    } catch (\KS\Exception\Shutdown $e) {
                        throw $e;
                    } catch (\Exception $e) {
                        $this->log("Exception thrown: {$e->getMessage()}", LOG_ERR, [ "syslog", STDERR ]);
                    }
                }

                sleep($this->config->getRunLoopInterval());
            } while (true);
        } catch (\KS\Exception\Shutdown $e) {
            $this->log("Caught ShutdownException. Exiting.", LOG_DEBUG);
        }
    }

    protected function getSelfIp()
    {
        $this->log("Getting self IP...", LOG_DEBUG);
        $attempts = 0;
        while (!($response = file_get_contents('http://checkip.dyndns.com/'))) {
            $attempts++;
            if ($attempts > 5) {
                $ip = null;
            }
        }

        if ($response) {
            preg_match('/Current IP Address: \[?([:.0-9a-fA-F]+)\]?/', $response, $ip);
            $ip = $ip[1];
        }

        if ($ip) {
            $this->log("Self IP found: $ip", LOG_DEBUG);
            return $ip;
        } else {
            $err = "Couldn't get our IP address. Is Dyndns down?";
            $this->log($err, LOG_ALERT);
            throw new Exception\SelfIPNotFound($err);
        }
    }

    protected function getRegisteredIp($hostname)
    {
        // If we haven't checked it yet, or we're due to check it....
        if (!array_key_exists($hostname, $this->registeredIps) ||
            !$this->lastIpCheck ||
            $this->lastIpCheck < time()-$this->config->getCheckInterval()
        ) {
            $this->log("Getting registered IP for $hostname", LOG_DEBUG);
            // Try three times to ping the host
            $attempts = 0;
            do {
                exec("ping -c 1 $hostname", $output, $returnVal);
                if ($returnVal > 0) {
                    sleep(5);
                }
                $attempts++;
            } while ($returnVal > 0 && $attempts < 3);

            // If we get errors each time, then we're out of luck
            if ($returnVal > 0) {
                $this->registeredIps[$hostname] = null;
                $this->log("Tried 3 times to get IP for $hostname but couldn't.", LOG_WARNING);
            } else {
                preg_match("/^PING $hostname \(([^)]+)/", $output[0], $ip);
                $ip = $ip[1];
                $this->registeredIps[$hostname] = $ip;
                $this->lastIpCheck = time();
                $this->log("IP for $hostname found: $ip. Setting ip timestamp to {$this->lastIpCheck}", LOG_DEBUG);
            }
        } else {
            $this->log("Don't need to check ip for $hostname yet... (Timestamp: {$this->lastIpCheck}", LOG_DEBUG);
        }
        return $this->registeredIps[$hostname];
    }

    public function shutdown()
    {
        $this->log("Shutting down.", LOG_INFO, [ "syslog", STDERR ], true);
        throw new \KS\Exception\Shutdown("Shutting down {$this->logIdentifier}");
    }
}

