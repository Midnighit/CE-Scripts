<?php
$stime = microtime(true);
/******************************************
* Functions, global variables & constants *
******************************************/

const MAX_STACK_SIZE = 1000000;
const MAX_PRODUCER = 1000;
const PRODUCER_ID = array('13012', '18002', '18010', '18011', '18012', '18020', '18021', '18022', '80995', '80991', '80992', '80993', '80994', '80995');

/*********************
* Table preparations *
*********************/

echo 'Updating stackable items in ItemTable.json... ';

/// items Table:
// complile all the relevant information into the items table
// Key: ItemId / Value: Array with MaxStackSize
$json = file_get_contents('./db/ItemTable.json');
$result = json_decode($json, true);
$new_row = 0;
foreach($result as $row => $value)
{
	if($value['MaxStackSize'] > 1)
	{
		$out_incr[$new_row] = $result[$row];
		if(in_array($value['RowName'], PRODUCER_ID)) $out_incr[$new_row++]['MaxStackSize'] = MAX_PRODUCER;
		else $out_incr[$new_row++]['MaxStackSize'] = MAX_STACK_SIZE;
	}
}
echo "done!\n";

echo 'Writing changes to ItemTable.UpdatedStackSize.json... ';
$json = json_encode($out_incr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$handle = fopen('./db/ItemTable.UpdatedStackSize.json', 'w+');
fwrite($handle, $json);
echo "done!\n";

$etime = microtime(true);
$diff = $etime - $stime;
echo "\nRequired time: ".round($diff,3)." sec.";
?>
