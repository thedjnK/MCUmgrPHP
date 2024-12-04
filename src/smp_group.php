<?PHP
namespace MCUmgr;

abstract class smp_group
{
	const SMP_GROUP_ID_OS = 0;
	const SMP_GROUP_ID_IMG = 1;
	const SMP_GROUP_ID_STATS = 2;
	const SMP_GROUP_ID_SETTINGS = 3;
	const SMP_GROUP_ID_FS = 8;
	const SMP_GROUP_ID_SHELL = 9;
	const SMP_GROUP_ID_ENUM = 10;
	const SMP_GROUP_ID_ZEPHYR = 63;
	const SMP_GROUP_ID_USER_DEFINED = 64;

	protected $processor;
	protected $message = NULL;
	protected $name;
	protected $smp_version;
	protected $smp_mtu;
	protected $smp_retries;
	protected $smp_timeout;
	protected $smp_user_data;
	protected $mode;
	protected $active_promise = NULL;
	protected $error_lookup = NULL;

	function __construct(&$processor, $group_name, $group_id)
	{
		$this->processor = &$processor;
		smp_error::register_error_lookup_function($group_id, $this->error_lookup);
		$this->name = $group_name;
	}

	public function set_parameters($version, $mtu, $retries, $timeout, $user_data)
	{
		$this->smp_version = $version;
		$this->smp_mtu = $mtu;
		$this->smp_retries = $retries;
		$this->smp_timeout = $timeout;
		$this->smp_user_data = $user_data;
	}

	abstract protected function cleanup();

	static public function lookup_error($rc): ?string
	{
		if (!is_null($this->error_lookup) && isset($this->error_lookup[$rc]))
		{
			return $this->error_lookup[$rc][1];
		}

		return NULL;
	}

	public function lookup_error_define($rc): ?string
	{
		if (!is_null($this->error_lookup) && isset($this->error_lookup[$rc]))
		{
			return $this->error_lookup[$rc][0];
		}

		return NULL;
	}

	public function lookup_error_full($rc): array
	{
		if (!is_null($this->error_lookup) && isset($this->error_lookup[$rc]))
		{
			return $this->error_lookup[$rc];
		}

		return NULL;
	}

	public function check_message_before_send(&$message): bool
	{
		if (strlen($message->data()) > $this->processor->max_message_data_size($this->smp_mtu))
		{
			$this->cleanup();
			return false;
		}
		else
		{
			return true;
		}
	}
}
?>
