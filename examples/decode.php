<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: decode.php
**
** Notes: This example demonstrates decoding SMP message on the command line
**        and will display information on the provided request/response
**        (supports hex-encoded SMP messages)
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

use CBOR\Decoder;
use CBOR\MapObject;
use CBOR\OtherObject\UndefinedObject;
use CBOR\TextStringObject;
use CBOR\ListObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use CBOR\OtherObject\TrueObject;
use CBOR\OtherObject\FalseObject;
use CBOR\OtherObject\NullObject;
use CBOR\Tag\DecimalFractionTag;
use CBOR\Tag\TimestampTag;
use CBOR\StringStream;

use MCUmgr\smp_message;
use MCUmgr\smp_error;
use MCUmgr\smp_group;

$newline = "\n";

if (!isset($argv[1]))
{
	echo 'Usage: decode.php <hex-or-base64-message>'.$newline;
	die();
}

//Check if this is a hex message or base64 SMP console message
$data = $argv[1];
$i = 0;
$l = strlen($data);
$base64 = false;

while ($i < $l)
{
	if (($data[$i] > 'f' && $data[$i] <= 'z') || ($data[$i] > 'F' && $data[$i] <= 'Z') || $data[$i] == '+' || $data[$i] == '/' || $data[$i] == '=')
	{
		$base64 = true;
		break;
	}

	++$i;
}

if ($base64)
{
	echo 'Input is base64, assuming SMP over console input'.$newline;
	echo 'Feature not currently supported'.$newline;
	die();
}
else
{
	echo 'Input is hex, assuming hex SMP input'.$newline;

	$i = 0;
	while ($i < $l)
	{
		if (!(($data[$i] >= 'a' && $data[$i] <= 'f') || ($data[$i] >= 'A' && $data[$i] <= 'F') || ($data[$i] >= '0' && $data[$i] <= '9')))
		{
			echo 'Invalid character found: '.$data[$i].$newline;
			die();
		}

		++$i;
	}

	if ((strlen($data) % 2) == 1)
	{
		echo 'Invalid data length provided'.$newline;
		die();
	}

	$message = new MCUmgr\smp_message();
	$message->append(hex2bin($data));

	if (!$message->is_valid())
	{
		echo 'Input is not valid!'.$newline;
		die();
	}

	if ($message->header_version() == 1)
	{
		echo 'SMP version 2 message'.$newline;
	}
	else
	{
		echo 'SMP version 1 message'.$newline;
	}

	if ($message->header_op() == smp_message::SMP_OP_READ)
	{
		echo 'Read op'.$newline;
	}
	else if ($message->header_op() == smp_message::SMP_OP_READ_RESPONSE)
	{
		echo 'Read response op'.$newline;
	}
	else if ($message->header_op() == smp_message::SMP_OP_WRITE)
	{
		echo 'Write op'.$newline;
	}
	else if ($message->header_op() == smp_message::SMP_OP_WRITE_RESPONSE)
	{
		echo 'Write response op'.$newline;
	}
	else
	{
		echo 'Unknown op'.$newline;
	}

	echo 'Sequence ID: '.$message->header_sequence().$newline;

	$group = $message->header_group();

	if ($group == smp_group::SMP_GROUP_ID_OS)
	{
		$group_text = 'OS management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_IMG)
	{
		$group_text = 'Image management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_STATS)
	{
		$group_text = 'Statistics management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_SETTINGS)
	{
		$group_text = 'Settings management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_FS)
	{
		$group_text = 'Filesystem management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_SHELL)
	{
		$group_text = 'Shell management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_ENUM)
	{
		$group_text = 'Enumeration management group';
	}
	else if ($group == smp_group::SMP_GROUP_ID_ZEPHYR)
	{
		$group_text = 'Zephyr basic management group';
	}
	else if ($group >= smp_group::SMP_GROUP_ID_USER_DEFINED)
	{
		$group_text = 'User-defined group';
	}
	else
	{
		$group_text = 'Unknown group';
	}

	echo 'Group: '.$group_text.' ('.$group.')'.$newline;
	echo 'Command: ('.$message->header_message_id().')'.$newline;
}
?>
