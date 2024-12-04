<?PHP
namespace MCUmgr;

use React\Promise\ExtendedPromiseInterface;
use React\Promise\Deferred;

class smp_transport_lorawan extends smp_transport
{
	private $config_hostname = '';
	private $config_port = '';
	private $config_tls = false;
	private $config_username = '';
	private $config_password = '';
	private $config_topic = '';
	private $config_frame_port = 0;
	private $config_set = false;
	private $mqtt = NULL;
	private $receive_message = NULL;
	private $receive_enabled = false;

	//Only to be used internally, public scope needed due to dire phpmqtt library
	public function mqtt_message_received($topic, $message, $retained, $matched_wildcards)
	{
		if ($this->receive_enabled == true)
		{
			$data = json_decode($message, true);

			if ($data['uplink_message']['f_port'] == $this->config_frame_port && isset($data['uplink_message']['frm_payload']))
			{
				$data = base64_decode($data['uplink_message']['frm_payload']);
				$this->receive_message->append($data);
			}
		}
	}

	public function set_connection_config($configuration): int
	{
		if ($this->is_connected() == true)
		{
			return smp_transport::SMP_TRANSPORT_ERROR_ALREADY_CONNECTED;
		}

		if (!isset($configuration['hostname']) || !isset($configuration['port']) || !isset($configuration['tls']) || !isset($configuration['username']) || !isset($configuration['password']) || !isset($configuration['topic']) || !isset($configuration['frame_port']))
		{
			return smp_transport::SMP_TRANSPORT_ERROR_INVALID_CONFIGURATION;
		}


		$this->config_hostname = $configuration['hostname'];
		$this->config_port = $configuration['port'];
		$this->config_tls = $configuration['tls'];
		$this->config_username = $configuration['username'];
		$this->config_password = $configuration['password'];
		$this->config_topic = $configuration['topic'];
		$this->config_frame_port = $configuration['frame_port'];
		$this->config_set = true;

		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function connect(): int
	{
		$this->mqtt = new \PhpMqtt\Client\MqttClient($this->config_hostname, $this->config_port);
		$this->mqtt->connect((new \PhpMqtt\Client\ConnectionSettings)->setUsername($this->config_username)->setPassword($this->config_password)->setUseTls($this->config_tls)->setConnectTimeout(5)->setTlsVerifyPeer(false), true);
		$this->mqtt->subscribe($this->config_topic.'/up', \Closure::fromCallable([$this, 'mqtt_message_received']), 0);
		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function disconnect($force): int
	{
		if (is_null($this->mqtt))
		{
			return smp_transport::SMP_TRANSPORT_ERROR_NOT_CONNECTED;
		}

		$this->mqtt->unsubscribe($this->config_topic.'/up');
		$this->mqtt->disconnect();
		$this->mqtt = NULL;
		return smp_transport::SMP_TRANSPORT_ERROR_OK;
	}

	public function is_connected(): bool
	{
		if (!is_null($this->mqtt))
		{
			return 1;
		}

		return 0;
	}

	public function send(&$message): int
	{
		$data = array
			(
				'downlinks' => array
				(
					array
					(
						'f_port' => $this->config_frame_port,
						'frm_payload' => base64_encode($message->data()),
						'priority' => 'NORMAL',
					)
				)
			);



		$this->mqtt->publish($this->config_topic.'/down/push', json_encode($data), 0);
//		$this->mqtt->publish($this->config_topic.'/down/replace', json_encode($data), 0);

		return 0;
	}

	public function receive($max_wait_ms = 0): \React\Promise\Promise
	{
		$deferred = new Deferred();
		$this->receive_message = new smp_message();
		$this->receive_enabled = true;
		$end_time = microtime(true) + ((float)$max_wait_ms / 1000.0);

		//Use loop once in a loop instead of loop due to complete inability to exit without unsubscribinng, which is impossible unless in the response recieved handler or using a ctrl+c signal handler, very badly designed API
		while (microtime(true) < $end_time && $this->receive_message->is_valid() == false)
		{
			$this->mqtt->loopOnce(microtime(true), true, 400000);
		}

		if ($this->receive_message->is_valid() == true)
		{
			$deferred->resolve($this->receive_message);
		}
		else
		{
			$deferred->reject(smp_transport::error(smp_transport::SMP_TRANSPORT_ERROR_TIMEOUT));
		}

		$this->receive_message = NULL;
		$this->receive_enabled = false;
		return $deferred->promise();
	}

	public function get_retries(): int
	{
		return 0;
	}

	public function get_timeout(): int
	{
		return 240000;
	}

        public static function to_error_string($error_code)
        {
		if ($error_code > smp_transport::SMP_TRANSPORT_ERROR_TRANSPORT_DEFINED_START)
		{
			return parent::to_error_string($error_code);
		}

                return 'TODO';
	}
}
?>
