

DynamoDB snippets
=====================

 - DynamoDb  local implementation

```php

$client = \Aws\DynamoDb\DynamoDbClient::factory(array(
		    'key' => 'XXXXXXXX',
		    'secret' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXX',
		    'region' => 'us-west-2',
		    'base_url' => 'http://localhost:8000'
		));

		$client->createTable(array(
			    'TableName' => 'errors',
			    'AttributeDefinitions' => array(
			        array(
			            'AttributeName' => 'id',
			            'AttributeType' => 'N'
			        ),
			        array(
			            'AttributeName' => 'time',
			            'AttributeType' => 'N'
			        )
			    ),
			    'KeySchema' => array(
			        array(
			            'AttributeName' => 'id',
			            'KeyType'       => 'HASH'
			        ),
			        array(
			            'AttributeName' => 'time',
			            'KeyType'       => 'RANGE'
			        )
			    ),
			    'ProvisionedThroughput' => array(
			        'ReadCapacityUnits'  => 10,
			        'WriteCapacityUnits' => 20
			    )
			));

		pr($client->listTables());

```


We can access javascript shell using the http port 800

http://localhost:8000/shell