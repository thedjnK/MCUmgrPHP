<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: commands.php
**
** Notes: Demonstrates how to issue commands and process responses, uses the
**        UART transport on /dev/ttyACM0 at 115200 baud, issue an echo
**        command and image state get
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
include('vendor/autoload.php');

use React\EventLoop\Loop;

use React\Promise\ExtendedPromiseInterface;
use React\Promise\Deferred;

use MCUmgr\smp_message;
use MCUmgr\smp_error;
use MCUmgr\smp_transport;
use MCUmgr\smp_transport_uart;
use MCUmgr\smp_group;
use MCUmgr\smp_group_os;
use MCUmgr\smp_group_img;
use MCUmgr\smp_processor;

function set_group_transport_settings(&$group, &$transport, &$processor)
{
	global $smp_v2;
	global $smp_mtu;
	global $mode;

	$processor->set_transport($transport);
	$group->set_parameters($smp_v2, $smp_mtu, $transport->get_retries(), $transport->get_timeout(), $mode);
}

//Setup objects
$processor = new smp_processor();
$os_group = new smp_group_os($processor);
$img_group = new smp_group_img($processor);
$uart_transport = new smp_transport_uart();

//Configuration
$smp_v2 = true;
$smp_mtu = 256;
$mode = 0;
$newline = "\n";
$config_uart = array('port' => '/dev/ttyACM0', 'baud' => 115200);

//Setup, then connect to transport
$error = $uart_transport->set_connection_config($config_uart);

if ($error != smp_transport::SMP_TRANSPORT_ERROR_OK)
{
	echo 'UART transport setup failed: '.$error.$newline;
	die();
}

$error = $uart_transport->connect();

if ($error != smp_transport::SMP_TRANSPORT_ERROR_OK)
{
	echo 'UART transport open failed: '.$error.$newline;
	die();
}

//Set group transport settings and issue commands
set_group_transport_settings($os_group, $uart_transport, $processor);
set_group_transport_settings($img_group, $uart_transport, $processor);
$os_group->start_echo('test data')->then(
	function ($data) use ($newline)
	{
		echo 'OS echo command OK, response: '.$data.$newline;
	},
	function ($data) use ($newline)
	{
		echo 'OS echo command failed'.$newline;
	}
);
$img_group->start_image_get()->then(
	function ($data) use ($newline)
	{
		$i = 0;
		echo 'Image info:'.$newline;

		foreach ($data as $image)
		{
			echo "\tImage ".$i.':'.$newline;
			echo "\t\tSlot: ".$image['slot'].$newline;
			echo "\t\tVersion: ".$image['version'].$newline;
			echo "\t\tHash: ".bin2hex($image['hash']).$newline;

			if (isset($image['bootable']) && $image['bootable'] == 1)
			{
				echo "\t\tBootable".$newline;
			}

			if (isset($image['confirmed']) && $image['confirmed'] == 1)
			{
				echo "\t\tConfirmed".$newline;
			}

			if (isset($image['active']) && $image['active'] == 1)
			{
				echo "\t\tActive".$newline;
			}

			echo $newline;
		}
	},
	function ($data) use ($newline)
	{
		echo 'Image image get command failed'.$newline;
	}
);
$uart_transport->disconnect(false);
?>
