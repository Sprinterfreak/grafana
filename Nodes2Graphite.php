#!/usr/bin/php
<?php
/**
 * Class Nodes2Grafana
 */
class Nodes2Graphite
{
	/**
	 * @var string Grafana host IP
	 */
	private $graphiteHost;
	/**
	 * @var integer Grafana host IP
	 */
	private $graphitePort;
	/**
	 * @var resource Graphite socket
	 */
	private $graphiteSocket;
	/**
	 * Nodes2Graphite constructor.
	 * @param string $graphiteHost
	 * @param integer $graphitePort
	 */
	public function __construct($graphiteHost, $graphitePort)
	{
		$this->graphiteHost = $graphiteHost;
		$this->graphitePort = $graphitePort;
	}
	/**
	 * Prepeare data for graphite
	 * @param string $nodefile
	 * @param string $namespace
	 */
	public function prepearData($nodefile, $namespace)
	{
		$nodeJson = file_get_contents($nodefile);
		$object = json_decode($nodeJson);
		$nodes = &$object->nodes;
		$online = 0;
		$clients = 0;
		$firmwares = Array();
		foreach ($nodes as $node) {
			$node_id = $this->saveProp($node,'nodeinfo.node_id');
			$hostname = $this->saveProp($node,'nodeinfo.hostname');
			if ($this->saveProp($node, 'flags.online', false) === true) {
				$nodeNamespace = $namespace . '.nodes.' . $node_id . '.' . $hostname . '.';
				$online++;
				$clients += $this->saveProp($node, 'statistics.clients');
				@$firmwares[$this->saveProp($node, 'nodeinfo.software.firmware.base', 'unknown')] += 1;
				$this->sendToGraphite($nodeNamespace . 'flags.online', 1);
				$this->sendToGraphite($nodeNamespace . 'hostname.' . $hostname, true);
				$this->sendToGraphite($nodeNamespace . 'model.' . $this->saveProp($node, 'nodeinfo.hardware.model', 'unknown'), true);
				$this->sendToGraphite($nodeNamespace . 'statistics.traffic.tx', $this->saveProp($node, 'statistics.traffic.tx.bytes'));
				$this->sendToGraphite($nodeNamespace . 'statistics.traffic.rx', $this->saveProp($node, 'statistics.traffic.rx.bytes'));
				$this->sendToGraphite($nodeNamespace . 'statistics.traffic.mgmt_tx', $this->saveProp($node, 'statistics.traffic.mgmt_tx.bytes'));
				$this->sendToGraphite($nodeNamespace . 'statistics.traffic.mgmt_rx', $this->saveProp($node, 'statistics.traffic.mgmt_rx.bytes'));
				$this->sendToGraphite($nodeNamespace . 'statistics.traffic.forward', $this->saveProp($node, 'statistics.traffic.forward.bytes'));
				$this->sendToGraphite($nodeNamespace . 'statistics.loadavg', $this->saveProp($node, 'statistics.loadavg'));
				$this->sendToGraphite($nodeNamespace . 'statistics.memory_usage', $this->saveProp($node, 'statistics.memory_usage'));
				$this->sendToGraphite($nodeNamespace . 'statistics.uptime', $this->saveProp($node, 'statistics.uptime'));
				$this->sendToGraphite($nodeNamespace . 'statistics.clients', $this->saveProp($node, 'statistics.clients'));
			}
		}
		$this->sendToGraphite($namespace . '.mesh.online', $online);
		$this->sendToGraphite($namespace . '.mesh.clients', $clients);
		foreach($firmwares as $ver => $ct) {
			$this->sendToGraphite($namespace . '.firmware.' . $ver, $ct);
		}
	}
	/**
	 * Check $node structure integrity
	 * @param object $node
	 * @param string dot seperated prop path
	 * @param string Fallback value
	 */
	private function saveProp($node, $propPath, $fallback = '')
	{
		$list = explode('.', $propPath);
		foreach($list as $item) {
			if(property_exists($node, $item)) {
				$node = $node->$item;
			} else {
				return $fallback;
			}
		}
		if(is_string($node))
			return $this->sanitize($node);
		elseif(is_numeric($node) || is_bool($node))
			return $node;
		return $fallback;
	}
	/**
	 * @param string $string UTF-8 input
	 * @return string Allowed chars
	 */
	private function sanitize($string)
	{
		return preg_replace('/[^a-z0-9\-\_]/i', '_', $string);
	}
	/**
	 * @param string $key
	 * @param mixed $value
	 */
	private function sendToGraphite($key, $value)
	{
		$this->openSocket();
		echo "Sending to graphite.... $key => $value\n";
		try {
			fwrite($this->graphiteSocket, $key . ' ' . $value . ' ' . time() . PHP_EOL);
		} catch (Exception $e) {
			echo "\nNetwork error: " . $e->getMessage();
		}
	}
	private function openSocket()
	{
		if ($this->graphiteSocket === null) {
			$this->graphiteSocket = fsockopen('tcp://' . $this->graphiteHost, $this->graphitePort, $errno, $errstr);
			if (!$this->graphiteSocket) {
				echo "$errstr ($errno)\n";
			}
		}
	}
	public function closeSocket()
	{
		if ($this->graphiteSocket !== null) {
			fclose($this->graphiteSocket);
		}
	}
}
$nodes2Graphite = new Nodes2Graphite('127.0.0.1', 2003);
$nodes2Graphite->prepearData($argv[2], $argv[1]);
$nodes2Graphite->closeSocket();
