<?php

define('RET_OK_NO_CHANGES',       0);
define('RET_OK_NO_RISKY_CHANGES', 1);
define('RET_OK_NO_DATA_LOSS',     2);
define('RET_OK',                  3);
define('RET_ERROR',               4);

$Options = array();
$Options[] = 'current-host:';
$Options[] = 'current-user:';
$Options[] = 'current-password:';
$Options[] = 'current-database:';

$Options[] = 'wanted-host:';
$Options[] = 'wanted-user:';
$Options[] = 'wanted-password:';
$Options[] = 'wanted-database:';

$Options[] = 'verbose::';
$Options[] = 'out-file::';

// Overriding the error handler
function MyErrorHandler($errno, $errstr, $errfile, $errline ) {
	throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}
set_error_handler("MyErrorHandler");
	
function ExceptionResult( Exception $E, $Context )
{
	fwrite(STDERR, '['.$E->getFile().':'.$E->getLine().']' . $Context.' : '.$E->getMessage()."\n");
	exit (RET_ERROR);
}
	


$Verbose = false;
$Out = STDOUT;


$HasChanges_NoRisky = false;
$HasChanges_WithRisks = false;
$HasChanges_WithDataLoss = false;

try{
	$OptionsValues = getopt('', $Options);
	
	if(array_key_exists('verbose', $OptionsValues) && $OptionsValues['verbose'])
		$Verbose = true;
	
	if(array_key_exists('out-file', $OptionsValues)) {
		$Out = fopen($OptionsValues['out-file'], 'w');		
	}
	
	$CurrentHost         = $OptionsValues['current-host'];
	$CurrentUser         = $OptionsValues['current-user'];
	$CurrentPassword     = $OptionsValues['current-password'];
	$CurrentDatabaseName = $OptionsValues['current-database'];
	
	$WantedHost          = $OptionsValues['wanted-host'];
	$WantedUser          = $OptionsValues['wanted-user'];
	$WantedPassword      = $OptionsValues['wanted-password'];
	$WantedDatabaseName  = $OptionsValues['wanted-database'];
	
} catch ( Exception $E ) {
	ExceptionResult($E, 'Error when parsing parameters');
}


function GetDatabaseSchema( $Connection, $DatabaseName )
{
	$TablesRes = $Connection->query( sprintf('SHOW FULL TABLES IN %1$s', $DatabaseName) );
	$Schema = array();
	while( list($TableName, $TableType)  = $TablesRes->fetch_row() )
	{
		if($TableType != 'BASE TABLE') {
			throw new ErrorException("Only tables are supported", 0, 0, __FILE__, __LINE__);
		}
		
		$TableRes = $Connection->query( sprintf('SHOW FULL COLUMNS FROM `%2$s` IN `%1$s`',$DatabaseName, $TableName) );
		$Fields = array();
		while( $TableAssoc = $TableRes->fetch_assoc() )
		{
			$FieldName      = $TableAssoc['Field'];
			$FieldType      = $TableAssoc['Type'];
			$FieldCollation = $TableAssoc['Collation'];
			$FieldNull      = $TableAssoc['Null'] === 'YES';
			$FieldDefault   = $TableAssoc['Default'];
			$FieldComment   = $TableAssoc['Comment'];
			
			$AutoIncrement = ( strpos( $TableAssoc['Extra'], 'auto_increment' ) ? true : false);
			$Definition = 
				$FieldType.' '.
				(($FieldNull ) ? 'NULL' : 'NOT NULL' ).' '.
				(($FieldDefault) ? 'DEFAULT '.$FieldDefault : '' ).' '.
				(($AutoIncrement) ? 'AUTO_INCREMENT': '' ).' '.
				'COMMENT \''.$FieldComment.'\''
				;
			
			$Fields[$FieldName] = array(
				'Name'      => $FieldName,
				'Type'      => $FieldType,
				'Collation' => $FieldCollation,
				'Null'      => $FieldNull,
				'Default'   => $FieldDefault,
				'Comment'   => $FieldComment,
				'Create'    => sprintf('ALTER TABLE `%1$s`.`%2$s` ADD `%3$s` %4$s',$DatabaseName, $TableName, $FieldName, $Definition ),
				'Drop'      => sprintf('ALTER TABLE `%1$s`.`%2$s` DROP COLUMN `%3$s`',$DatabaseName, $TableName, $FieldName ),
			);
		}
		
		$TableRes = $Connection->query( sprintf('SHOW INDEXES FROM `%2$s` IN `%1$s`',$DatabaseName, $TableName) );
		$Indexes = array();
		while( $TableAssoc = $TableRes->fetch_assoc() )
		{
			$IndexName = $TableAssoc['Key_name'];
			if( !array_key_exists($IndexName, $Indexes)  )
			{
				$Indexes[$IndexName] = array(
					'Name'      => $IndexName,
					'Type'      => $TableAssoc['Index_type'],
					'Columns'   => array( $TableAssoc['Seq_in_index'] => $TableAssoc['Column_name']),
					'Unique'    => !$TableAssoc['Non_unique'],
				);
			} else {
				$Indexes[$IndexName]['Columns'][$TableAssoc['Seq_in_index']] = $TableAssoc['Column_name'];
			}
		}
		
		$TableRes = $Connection->query( sprintf('SHOW CREATE TABLE `%1$s`.`%2$s`', $DatabaseName, $TableName) );
		$Create = $TableRes->fetch_assoc()['Create Table'];
		
		$TableRes = $Connection->query( sprintf('SHOW TABLE STATUS IN `%1$s` LIKE \'%2$s\'', $DatabaseName, $TableName) );
		$Engine = $TableRes->fetch_assoc()['Engine'];
		$Collation = $TableRes->fetch_assoc()['Collation'];
		
		$Schema[$TableName] = 
		array( 
			'Name'       => $TableName,
			'Fields'     => $Fields,
			'Indexes'    => $Indexes,
			'Engine'     => $Engine,
			'Collation'  => $Collation,
			'Create'     => $Create,
			'Drop'       => sprintf('DROP TABLE `%1$s`.`%2$s`', $DatabaseName, $TableName),
		);
	}
	return $Schema;
}

try
{
	$CurrentDatabaseConnection = mysqli_connect($CurrentHost, $CurrentUser, $CurrentPassword, '');
	if ($CurrentDatabaseConnection->connect_errno) {
		throw new ErrorException("Failed to connect to current database: " . $CurrentDatabaseConnection->connect_error, 0, $CurrentDatabaseConnection->connect_errno, __FILE__, __LINE__);
	}

	$WantedDatabaseConnection  = mysqli_connect($WantedHost, $WantedUser, $WantedPassword, '');
	if ($WantedDatabaseConnection->connect_errno) {
		throw new ErrorException("Failed to connect to wanted database: " . $WantedDatabaseConnection->connect_error, 0, $WantedDatabaseConnection->connect_errno, __FILE__, __LINE__);
	}

	//*
	$WantedShema = GetDatabaseSchema($WantedDatabaseConnection, $WantedDatabaseName);
	$CurrentShema = GetDatabaseSchema($CurrentDatabaseConnection, $CurrentDatabaseName);
	/*/
	// Reverse when you want to debug
	$CurrentShema = GetDatabaseSchema($WantedDatabaseConnection, $WantedDatabaseName);
	$WantedShema  = GetDatabaseSchema($CurrentDatabaseConnection, $CurrentDatabaseName);
	//*/
	
} catch ( Exception $E ) {
	ExceptionResult($E, 'Error when fetching database schema');
}

//print_r($WantedShema);

$ResultSQL = '';

function CompareTableName( $S1, $S2 )       { return $S1 === $S2; }
function CompareTableEngine( $S1, $S2 )     { return $S1 === $S2; }
function CompareFieldName( $S1, $S2 )       { return $S1 === $S2; }
function CompareCollation( $S1, $S2 )       { return $S1 === $S2; }
function CompareField( $S1, $S2 )    
{ 
	return 
	$S1['Name']      ===  $S2['Name'] &&
	$S1['Type']      ===  $S2['Type'] &&
	$S1['Collation'] ===  $S2['Collation'] &&
	$S1['Null']      ===  $S2['Null'] &&
	$S1['Default']   ===  $S2['Default'] &&
	$S1['Comment']   ===  $S2['Comment']; 
}

try
{

	foreach($CurrentShema as $CurrentTable) {
		$WantedTable = Null;
		foreach($WantedShema as $Table) {
			if( CompareTableName($CurrentTable['Name'], $Table['Name'])) {
				$WantedTable = $Table;
				break;
			}
		}
		if(!$WantedTable) {
			$ResultSQL .= $CurrentTable['Drop']."\n\n";
			$HasChanges_WithDataLoss = true;
		}
	}

	foreach($WantedShema as $WantedTable) {
		$CurrentTable = Null;
		foreach($CurrentShema as $Table) {
			if(CompareTableName($Table['Name'],  $WantedTable['Name'])) {
				$CurrentTable = $Table;
				break;
			}
		}
		
		if(!$CurrentTable) {
			$ResultSQL .= $WantedTable['Create']."\n\n";
			$HasChanges_NoRisky = true;
		} else {
			// Compare Engine
			$ErrorPrefix1 = sprintf('[Table=%1$s]', $CurrentTable['Name']);
			if( !CompareTableEngine($CurrentTable['Engine'], $CurrentTable['Engine']) ) {
				throw new ErrorException($ErrorPrefix1."Engine update not supported yet", 0, 0, __FILE__, __LINE__);
			}
			// Compare Collation
			if( !CompareCollation($CurrentTable['Collation'], $CurrentTable['Collation']) ) {
				throw new ErrorException($ErrorPrefix1."Collation update not supported yet", 0, 0, __FILE__, __LINE__);
			}
			
			// Compare indexes
			
			// Compare fields
			foreach($CurrentTable['Fields'] as $CurrentField) {
				$WantedField = Null;
				foreach($WantedTable['Fields'] as $Field) {
					if(CompareFieldName($CurrentField['Name'], $Field['Name'])) {
						$WantedField = $Field;
						break;
					}
				}
				if(!$WantedField) {
					$ResultSQL .= $WantedField['Drop']."\n\n";
					$HasChanges_WithDataLoss = true;
				}
			}
			
			foreach($WantedTable['Fields'] as $WantedField) {
				$CurrentField = Null;
				foreach($CurrentTable['Fields'] as $Field) {
					if(CompareFieldName($WantedField['Name'], $Field['Name'])) {
						$CurrentField = $Field;
						break;
					}
				}
				if(!$CurrentField) {
					$ResultSQL .= $CurrentField['Create']."\n\n";
					$HasChanges_NoRisky = true;
				} else {
					$ErrorPrefix2 = $ErrorPrefix1 . sprintf('[Field=%1$s]', $CurrentField['Name']);
					if( !CompareField($CurrentField, $WantedField) ) {
						throw new ErrorException($ErrorPrefix2."Field update not supported yet", 0, 0, __FILE__, __LINE__);
					}
				}
			}
		}
	}
} catch ( Exception $E ) {
	ExceptionResult($E, 'Error when comparing databases');
}

try
{
	fwrite($Out, $ResultSQL );
} catch ( Exception $E ) {
	ExceptionResult($E, 'Error when writing result');
}

if($HasChanges_WithDataLoss) {
	exit (RET_OK);
} else if ($HasChanges_WithRisks) {
	exit (RET_OK_NO_DATA_LOSS);
} else if ($HasChanges_NoRisky) {
	exit (RET_OK_NO_RISKY_CHANGES);
} else {
	exit (RET_OK_NO_CHANGES);
}
