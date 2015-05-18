<?php

App::import('Vendor', 'vendor/autoload');

use Aws\DynamoDb\DynamoDbClient;

class ChangelogBehavior extends ModelBehavior {
	
	private $dynamoDb = null;

	private $dynamoTable = 'changelogs';
	
	private $ignoreFields = array();

	private $authorId = null;

	private $authorName = null;

	private $domain = null;

	//action - add|update|remove
	
	public function setup(Model $Model, $settings = array()) {
		$this->authorId = AuthComponent::user('id');
		$this->authorName = AuthComponent::user('name');
		$this->domain = $settings['domain'];
		if (!empty($settings['ignore'])) {
			$this->ignoreFields = $settings['ignore'];
		}
	}

	public function beforeSave(Model $Model, $settings = array()) {
		if (empty($this->authorId)) {
			return true;
		}
		if (!empty($Model->id)) {
			if (!empty($Model->data[$Model->alias]['deleted'])) {
				$changeSlug = $this->domain.'_'.$Model->alias.'_'.$Model->id;
				$notes = "{$this->authorName} Removed {$Model->alias} <{$Model->id}> on ".date('F j, Y H:ia');
				$revisionInfo = array('id' => array(\Aws\DynamoDb\Enum\Type::S => $changeSlug),
									'time' => array(\Aws\DynamoDb\Enum\Type::N => time()),
									'action' => array(\Aws\DynamoDb\Enum\Type::S => 'remove'),
									'module' => array(\Aws\DynamoDb\Enum\Type::S => $Model->alias),
									'module_id' => array(\Aws\DynamoDb\Enum\Type::N => $Model->id),
									'notes' => array(\Aws\DynamoDb\Enum\Type::S => $notes),
									'author' => array(\Aws\DynamoDb\Enum\Type::N => $this->authorId),
									'domain' => array(\Aws\DynamoDb\Enum\Type::S => $this->domain)
									);
				$this->saveRevision($revisionInfo);	
				return true;
			}

			list($diffData, $oldDataDiff) = $this->getDifference($Model);
			if (!empty($diffData)) {
				$changeSlug = $this->domain.'_'.$Model->alias.'_'.$Model->id;
				$notes = "{$this->authorName} updated {$Model->alias} <{$Model->id}> on ".date('F j, Y H:ia');
				$revisionInfo = array('id' => array(\Aws\DynamoDb\Enum\Type::S => $changeSlug),
									'time' => array(\Aws\DynamoDb\Enum\Type::N => time()),
									'action' => array(\Aws\DynamoDb\Enum\Type::S => 'update'),
									'module' => array(\Aws\DynamoDb\Enum\Type::S => $Model->alias),
									'before_update' => array(\Aws\DynamoDb\Enum\Type::S => $this->maybeSerialize($oldDataDiff)),
									'after_update' => array(\Aws\DynamoDb\Enum\Type::S => $this->maybeSerialize($diffData)),
									'module_id' => array(\Aws\DynamoDb\Enum\Type::N => $Model->id),
									'notes' => array(\Aws\DynamoDb\Enum\Type::S => $notes),
									'author' => array(\Aws\DynamoDb\Enum\Type::N => $this->authorId),
									'domain' => array(\Aws\DynamoDb\Enum\Type::S => $this->domain)
									);
				$this->saveRevision($revisionInfo);
			}
		}
		return true;
	}

	public function afterSave(Model $Model, $created, $options = array()) {
		if (empty($this->authorId) || $created == false) {
			return true;
		}

		$changeSlug = $this->domain.'_'.$Model->alias.'_'.$Model->id;
		$notes = "{$this->authorName} added a new {$Model->alias} <{$Model->id}> on ".date('F j, Y H:ia');
		$revisionInfo = array('id' => array(\Aws\DynamoDb\Enum\Type::S => $changeSlug),
						'time' => array(\Aws\DynamoDb\Enum\Type::N => time()),
						'action' => array(\Aws\DynamoDb\Enum\Type::S => 'add'),
						'module' => array(\Aws\DynamoDb\Enum\Type::S => $Model->alias),
						'module_id' => array(\Aws\DynamoDb\Enum\Type::N => $Model->id),
						'notes' => array(\Aws\DynamoDb\Enum\Type::S => $notes),
						'author' => array(\Aws\DynamoDb\Enum\Type::N => $this->authorId),
						'domain' => array(\Aws\DynamoDb\Enum\Type::S => $this->domain)
						);
		$this->saveRevision($revisionInfo);
		return true;
	}

	public function getAllChangelogs(Model $Model, $options = array()) {
		$this->initDynamoClient();
		$limit = !empty($options['rowsperpage']) ? $options['rowsperpage'] : 100;
		
		$queryArgs = array('TableName' => $this->dynamoTable,
							'IndexName' => 'Id-index',
							'ScanFilter' => array(
							 'Time' => array(
							 		'AttributeValueList' => array(
							 			array('N' => strtotime("-365 days")),
				
							 		),
							 		'ComparisonOperator' => 'GT' //GT, BETWEEN
							 )
							),
							'ConsistentRead' => true,
							'Limit' => $limit,
							'ScanIndexForward' => false,
						);

		$queryArgs = array('TableName' => $this->dynamoTable,
					'IndexName' => 'Id-index',
					'ScanFilter' => array(
					 'time' => array(
					 		'AttributeValueList' => array(
					 			array('N' => strtotime("-365 days")),
		
					 		),
					 		'ComparisonOperator' => 'GT' //GT, BETWEEN
					 )
					),
					'ConsistentRead' => true
			);
		if (!empty($options['ExclusiveStartKey'])) {
			$queryArgs['ExclusiveStartKey'] = $options['ExclusiveStartKey'];
		}
		
		$changelogs = array();
		$LastEvaluatedKey = array();
		try {
			$result = $this->dynamoDb->scan($queryArgs);
			if (!empty($result['Items'])) {
				foreach ($result['Items'] as $item) {
					$changelogs[] = array('id' => $item['id']['S'],
										'time' => $item['time']['N'],
										'author' => $item['author']['N'],
										'domain' => $item['domain']['S'],
										'after_update' => !empty($item['after_update']['S']) ? $this->maybeUnserialize($item['after_update']['S']) : array(),
										'before_update' => !empty($item['before_update']['S']) ? $this->maybeUnserialize($item['before_update']['S']) : array(),
										'action' => $item['action']['S'],
										'module_id' => $item['module_id']['N'],
										'module' => $item['module']['S'],
										'notes' => $item['notes']['S']
									);
				}
			}

			$LastEvaluatedKey = !empty($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : array();
		} catch(Exception $e) {
			echo $e->getMessage();
		}
		return array($changelogs, $LastEvaluatedKey);
	}

	public function getChangelog(Model $Model, $id = '', $options = array()) {
		$revision = array();
		if (empty($id)) {
			return $revision;
		}
		$this->initDynamoClient();
		
		$compOperator = empty($options['time']) ? 'GE' : 'EQ';
		$time = empty($options['time']) ? strtotime("-365 days") : $options['time'];
		$changelog = array();
		try {
			$changeSlug = $this->domain.'_'.$Model->alias.'_'.$id;
			$result = $this->dynamoDb->query(array('TableName' => $this->dynamoTable,
								'KeyConditions' => array(
									'id' => array(
											'AttributeValueList' => array(
												array(\Aws\DynamoDb\Enum\Type::S => $changeSlug)
											),
											'ComparisonOperator' => 'EQ'
									),
									'time' => array(
											'AttributeValueList' => array(
												array(\Aws\DynamoDb\Enum\Type::N => $time)
											),
											'ComparisonOperator' => $compOperator
									)
							),
							'Limit' => 1,
							'ScanIndexForward' => false
						));

			if (!empty($result['Items'][0])) {
				$changelog = array('id' => $result['Items'][0]['id']['S'],
									'time' => $result['Items'][0]['time']['N'],
									'author' => $result['Items'][0]['author']['N'],
									'domain' => $result['Items'][0]['domain']['S'],
									'after_update' => !empty($result['Items'][0]['after_update']['S']) ? $this->maybeUnserialize($result['Items'][0]['after_update']['S']) : array(),
									'before_update' => !empty($result['Items'][0]['before_update']['S']) ? $this->maybeUnserialize($result['Items'][0]['before_update']['S']) : array(),
									'action' => $result['Items'][0]['action']['S'],
									'module_id' => $result['Items'][0]['module_id']['N'],
									'module' => $result['Items'][0]['module']['S'],
									'notes' => $result['Items'][0]['notes']['S']
								);
			}
			return $changelog;
		} catch(Aws\DynamoDb\Exception\DynamoDbException $e) {}
		return $changelog;
	}

	private function initDynamoClient() {
		if ($this->dynamoDb != null) {
			return;
		}

		$AmazonConf = Configure::read('Amazon');
		$this->dynamoDb = DynamoDbClient::factory(array(
				'key'    => $AmazonConf['awsAccessKey'],
				'secret' => $AmazonConf['awsSecretKey'],
				'region' => 'eu-west-1'
		));
		return true;
	}

	private function saveRevision($revisionInfo) {
		$this->initDynamoClient();
		try {
			$this->dynamoDb->putItem(array('TableName' => $this->dynamoTable, 'Item' => $revisionInfo));
		} catch (Aws\DynamoDb\Exception\DynamoDbException $e) { pr($e->getMessage()); }
		return true;
	}

	public function getDifference($Model) {
		$modelDataDiff = array();
		$oldDataResult = $Model->find('first', array('conditions' => array($Model->primaryKey => $Model->id), 'recursive' => -1));
		$newData = $Model->data[$Model->alias];
		$oldData = !empty($oldDataResult[$Model->alias]) ? $oldDataResult[$Model->alias] : array();
		if (empty($newData) || empty($oldData)) {
			return array();
		}
		$oldDataDiff = array();
		foreach ($newData as $key => $value) {
			if (in_array($key, $this->ignoreFields) || !isset($oldData[$key])) {
				continue;
			}
			$newValue = $this->normalizeWhitespace($value);
			$oldValue = $this->normalizeWhitespace($oldData[$key]);
			if ($newValue != $oldValue) {
				$modelDataDiff[$key] = $newValue;
				$oldDataDiff[$key] = $oldValue;
			}
		}
		return array($modelDataDiff, $oldDataDiff);
	}
	
	private function normalizeWhitespace($str) {
		$str  = trim( $str );
		$str  = str_replace( "\r", "\n", $str );
		$str  = preg_replace( array( '/\n+/', '/[ \t]+/' ), array( "\n", ' ' ), $str );
		return $str;
	}
	
	private function maybeSerialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) )
			return serialize( $data );
		
		if ( $this->isSerialized( $data, false ) )
			return serialize( $data );
	
		return $data;
	}
	
	function maybeUnserialize( $original ) {
		if ( $this->isSerialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
			return @unserialize( $original );
		return $original;
	}
	
	private function isSerialized( $data, $strict = true ) {
		// if it isn't a string, it isn't serialized.
		if ( ! is_string( $data ) ) {
			return false;
		}
		$data = trim( $data );
		if ( 'N;' == $data ) {
			return true;
		}
		if ( strlen( $data ) < 4 ) {
			return false;
		}
		if ( ':' !== $data[1] ) {
			return false;
		}
		if ( $strict ) {
			$lastc = substr( $data, -1 );
			if ( ';' !== $lastc && '}' !== $lastc ) {
				return false;
			}
		} else {
			$semicolon = strpos( $data, ';' );
			$brace     = strpos( $data, '}' );
			// Either ; or } must exist.
			if ( false === $semicolon && false === $brace )
				return false;
			// But neither must be in the first X characters.
			if ( false !== $semicolon && $semicolon < 3 )
				return false;
			if ( false !== $brace && $brace < 4 )
				return false;
		}
		$token = $data[0];
		switch ( $token ) {
			case 's' :
				if ( $strict ) {
					if ( '"' !== substr( $data, -2, 1 ) ) {
						return false;
					}
				} elseif ( false === strpos( $data, '"' ) ) {
					return false;
				}
				// or else fall through
			case 'a' :
			case 'O' :
				return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
			case 'b' :
			case 'i' :
			case 'd' :
				$end = $strict ? '$' : '';
				return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
		}
		return false;
	}

}

?>