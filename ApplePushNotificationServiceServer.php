<?php

/*
 * job messages json encoded into redis acme.apns queue format:
	$job = [
		'message' => $message,
		'device_id' => string,
	];

	$message = [
		'aps' => [
			'content-available' => 1,
			'alert' => 'message to alert',
			'sound' => 'default',
			'badge' => int,
			'custom' => [
				'custom_field1' => 'something',
				'custom_field2' => 'something else'
			]
		]
	]
 */

class ApplePushNotificationServiceServer {

	const retries = 4;
	const SEND_INTERVAL = 250000; // microseconds ( 1/4th second )
	const APNS_CONNECTION_IDLE = 20; // seconds
	const CONNECTION_TIMEOUT = 3; // seconds

	protected $endpoint;
	protected $certificate;
	protected $caFile;
	protected $connection;
	protected $messageQueue;
	protected $counter = 0;

	protected $redis;
	protected $app;

	protected $connect = false;
	protected $connected = false;

	protected $accessPoints;

	public function __construct()
	{
		declare(ticks = 1);

		pcntl_signal(SIGTERM, [$this, 'handleSignal']);
		pcntl_signal(SIGINT, [$this, 'handleSignal']);

		$this->accessPoints = [
			'dev' => [
				'certificate' => './apns-dev.pem',
				'endpoint' => 'gateway.sandbox.push.apple.com:2195'
			],

			'prod' => [
				'certificate' => './apns-prod.pem',
				'endpoint' => 'gateway.push.apple.com:2195'
			]
		];

		$default = 'dev';
		$this->endpoint = $this->accessPoints[$default]['endpoint'];
		$this->certificate = $this->accessPoints[$default]['certificate'];
		$this->caFile = './entrust_root_certification_authority.pem';
	}

	public function handleSignal($signal) {
		$this->log('signal caught: '.$signal, 'warning');
		$this->log('cleaning apns server...');
		$this->disconnect();
		$this->log('done. quiting ...');
		exit;
	}

	private function log($msg, $level = 'info') {
		$now = date('Y-m-d H:i:s');
		$logMsg = "[{$now}] {$level}: {$msg}".PHP_EOL;
		echo $logMsg;
		return $logMsg;
	}

	public function fire()
	{
		$this->log('Weclipse APNS server starting...');
		$this->log('connecting to queue on localhost...');
		$this->redis = new Redis();
		if ($this->redis->pconnect('127.0.0.1')) {
			$this->log('connected');
		}
		else {
			$this->log('failed to connect. quiting...', 'error');
			return false;
		}
		$idleTime = 0;

		while (true) {
			if ($idleTime >= self::APNS_CONNECTION_IDLE) {
				$this->disconnect();
				$idleTime = 0;
			}

			while ( ($job = $this->redis->rPop('acme.apns'))) {
				$idleTime = 0;
				$messageData = json_decode($job, true);

				$this->counter++;
				$this->log('sending message # '.$this->counter.'...');
				$sent = $this->send($messageData);

				if ($sent === true)
					$this->log("sent");
				else
					$this->log("not sent");

				usleep(self::SEND_INTERVAL);
			}

			sleep(1);
			$idleTime++;
		}
		return true;
	}

	private function connect($reconnect = false)
	{
		$streamContext = stream_context_create([
			'ssl' => [
				'verify_peer' => true,
				'cafile' => $this->caFile,
				'local_cert' => $this->certificate
			]
		]);

		if ( ! $this->connection || $reconnect) {
			$this->disconnect();
			for ($i = 0; $i < self::retries; $i++) {
				try {
					$this->connection = stream_socket_client('ssl://' . $this->endpoint, $error, $errorString, self::CONNECTION_TIMEOUT, STREAM_CLIENT_CONNECT, $streamContext);
					if ($this->connection === false) {
						$this->log(($i + 1).' failed to connect to apns stream_socket_client', 'error');
						continue;
					}
					stream_set_blocking($this->connection, 0);
					stream_set_write_buffer($this->connection, 0);
					$this->connected = true;
					return true;
				}
				catch (Exception $e) {
					$this->log($e->getMessage(), 'error');
					$this->log(($i+1).' failed to connect to apns', 'warning');
				}
			}

			$this->connected = false;
			return false;
		}

		$this->log("already connected {$this->connection}");
		return true;
	}

	private function send($messageData)
	{
		if ( ! $this->connected) {
			$this->log("reconnecting ...");
			$this->connect(true);
		}

		$message = $messageData['message'];
		$message = json_encode($message);
		$deviceId = $messageData['device_id'];
		$apnsMessage = chr(0) . chr(0) . chr(32) . pack('H*', $deviceId) . chr(0) . chr(strlen($message)) . $message;
		$write = false;
		try {
			for ($i = 0; $i < 3; $i++) {
				$count = @fwrite($this->connection, $apnsMessage);
				$this->log($count);
				if ($count === false || $count === 0) {
					$this->log('-fwrite failed', 'warning');
					$this->connect(true);
				}
				else {
					$write = true;
					break;
				}
			}

		}
		catch (Exception $e) {
			$this->log($e->getMessage(), 'error');
			$this->log('fwrite failed', 'warning');
			$this->connect(true);
		}

		return $write;
	}

	private function disconnect()
	{
		if (is_resource($this->connection)) {
			$this->log("closing old connection {$this->connection}");
			$closed = @fclose($this->connection);
			if ($closed === true)
				$this->log('closed');
			else
				$this->log('not closed');

			$this->connected = false;
		}
	}
}

function exampleEnqueuePush() {
	$message = [
		'aps' => [
			'content-available' => 1,
			'alert' => 'message to alert',
			'sound' => 'default',
			'badge' => 1234,
			'custom' => [
				'custom_field1' => 'something',
				'custom_field2' => 'something else'
			]
		]
	];

	$job = [
		'message' => $message,
		'device_id' => '4595f0ab4e95cf6cbd9ecd1020af0d9d6ec69b65a2346b7a1b6ed0999d657aa4',
	];

	$redis = new Redis();
	$redis->pconnect('127.0.0.1');
	if ( ! $redis->lPush('acme.apns', json_encode($job)) ) {
		echo "failed to send job to queue".PHP_EOL;
	}
}

exampleEnqueuePush();

$server = new ApplePushNotificationServiceServer();
$server->fire();
