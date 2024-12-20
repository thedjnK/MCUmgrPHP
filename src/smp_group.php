<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_group.php
**
** Notes:
**
** License: Licensed under the Apache License, Version 2.0 (the "License");
**          you may not use this file except in compliance with the License.
**          You may obtain a copy of the License at
**
**              http://www.apache.org/licenses/LICENSE-2.0
**
**          Unless required by applicable law or agreed to in writing,
**          software distributed under the License is distributed on an
**          "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,
**          either express or implied. See the License for the specific
**          language governing permissions and limitations under the License.
**
*******************************************************************************/
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

	function __construct(&$processor, $group_name, $group_id)
	{
		$this->processor = &$processor;
		smp_error::register_error_lookup_function($group_id, __NAMESPACE__.'\\smp_group_'.$group_name);
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
		if ($rc == smp_error::SMP_ERROR_EOK || $rc == smp_error::SMP_ERROR_EUNKNOWN)
		{
			return smp_error::error_lookup[$rc][1];
		}

		$rc -= smp_error::SMP_GROUP_ERROR_OFFSET;

		if (isset($this->error_lookup[$rc]))
		{
			return $this->error_lookup[$rc][1];
		}

		return NULL;
	}

	public function lookup_error_define($rc): ?string
	{
		if ($rc == smp_error::SMP_ERROR_EOK || $rc == smp_error::SMP_ERROR_EUNKNOWN)
		{
			return smp_error::error_lookup[$rc][0];
		}

		$rc -= smp_error::SMP_GROUP_ERROR_OFFSET;

		if (isset($this->error_lookup[$rc]))
		{
			return $this->error_lookup[$rc][0];
		}

		return NULL;
	}

	public function lookup_error_full($rc): ?array
	{
		if ($rc == smp_error::SMP_ERROR_EOK || $rc == smp_error::SMP_ERROR_EUNKNOWN)
		{
			return smp_error::error_lookup[$rc];
		}

		$rc -= smp_error::SMP_GROUP_ERROR_OFFSET;

		if (isset($this->error_lookup[$rc]))
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
