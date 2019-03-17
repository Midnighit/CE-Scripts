<?php
echo '<pre>';

function createInsertStatement($table, $values)
{
	if($table == 'properties')
	{
		$sql = "INSERT INTO properties ('object_id', 'name') VALUES ";
		foreach($values as $row) $sql .= "(" . $row[0] . ", '" . $row[1] . "'), ";
		return substr($sql, 0, -2);
		
	}
	if(isset($values) && count($values) > 0)
	{
		$sql = 'INSERT INTO ' . $table . ' VALUES ';
		foreach($values as $row)
		{
			$sql .= '(';
			foreach($row as $value) is_string($value) ? ($sql .= "'" . $value . "', ") : ($sql .= $value . ", ");
			$sql = substr($sql, 0, -2) . '), ';
		}
		return substr($sql, 0, -2);
	}
	return false;
}
// Open the SQLite3 dbs
$dbSrc = new SQLite3('../db/game-vanilla.db');
$dbDst = new SQLite3('../db/game.db');

// buildings
$table = 'buildings';
$sql = 'SELECT * FROM ' . $table . ' WHERE owner_id = 0';
$result = $dbSrc->query($sql); $i = 0;
unset($values);
for($i = 0; $row = $result->fetchArray(SQLITE3_NUM); $i++) foreach($row as $col => $value) $values[$i][$col] = $value;
$result->finalize();
if(isset($values))
{
	$sql = createInsertStatement($table, $values);
	$dbDst->query($sql);
}

// actor_position
$table = 'actor_position';
$sql = 'SELECT * FROM ' . $table . ' WHERE id IN (SELECT object_id FROM buildings WHERE owner_id = 0)';
$result = $dbSrc->query($sql);
unset($values);
for($i = 0; $row = $result->fetchArray(SQLITE3_NUM); $i++) foreach($row as $col => $value) $values[$i][$col] = $value;
$result->finalize();
if(isset($values))
{
	$sql = createInsertStatement($table, $values);
	$dbDst->query($sql);
}

// buildable_health
$table = 'buildable_health';
$sql = 'SELECT * FROM ' . $table . ' WHERE object_id IN (SELECT object_id FROM buildings WHERE owner_id = 0)';
$result = $dbSrc->query($sql);
unset($values);
for($i = 0; $row = $result->fetchArray(SQLITE3_NUM); $i++) foreach($row as $col => $value) $values[$i][$col] = $value;
$result->finalize();
if(isset($values))
{
	$sql = createInsertStatement($table, $values);
	$dbDst->query($sql);
}

// building_instances
$table = 'building_instances';
$sql = 'SELECT * FROM ' . $table . ' WHERE object_id IN (SELECT object_id FROM buildings WHERE owner_id = 0)';
$result = $dbSrc->query($sql);
unset($values);
for($i = 0; $row = $result->fetchArray(SQLITE3_NUM); $i++) foreach($row as $col => $value) $values[$i][$col] = $value;
$result->finalize();
if(isset($values))
{
	$sql = createInsertStatement($table, $values);
	$dbDst->query($sql);
}

// destruction_history
$table = 'destruction_history';
$sql = 'SELECT * FROM ' . $table . ' WHERE object_id IN (SELECT object_id FROM buildings WHERE owner_id = 0)';
$result = $dbSrc->query($sql);
unset($values);
for($i = 0; $row = $result->fetchArray(SQLITE3_NUM); $i++) foreach($row as $col => $value) $values[$i][$col] = $value;
$result->finalize();
if(isset($values))
{
	$sql = createInsertStatement($table, $values);
	$dbDst->query($sql);
}
/*
// properties
$table = 'properties';
$sql = 'SELECT * FROM ' . $table . ' WHERE object_id IN (SELECT object_id FROM buildings WHERE owner_id = 0)';
$result = $dbSrc->query($sql);
unset($values);
for($i = 0; $row = $result->fetchArray(SQLITE3_NUM); $i++) foreach($row as $col => $value) $values[$i][$col] = $value;
$result->finalize();
if(isset($values))
{
	$sql = createInsertStatement($table, $values);
	echo $sql;
	$dbDst->query($sql);
}
*/
unset($dbSrc);
unset($dbDst);
echo '</pre>';
?>