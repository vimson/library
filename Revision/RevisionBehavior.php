<?php 

App::import('Vendor', 'vendor/autoload');

use Aws\DynamoDb\DynamoDbClient;

class RevisionBehavior extends ModelBehavior {
	
	private $ignoreFields = array();
	
	private $processRevisions = false;
	
	private $saveRevisions = true;
	
	private $dynamoTable = 'revisions';
	
	private $authorPrefix = '';
	
	private $authorField = '';
	
	private $dynamoDb = null;
	
	public function setup(Model $Model, $settings = array()) {
		$this->processRevisions = isset($settings['process_revisions']) ? $settings['process_revisions'] : false;
		$this->saveRevisions = isset($settings['save_revisions']) ? $settings['save_revisions'] : true;
		$this->authorPrefix = isset($settings['author_prefix']) ? '_'.$settings['author_prefix'] : '';
		$this->authorField = isset($settings['author_field']) ? $settings['author_field'] : '';
		if (!empty($settings['ignore'])) {
			$this->ignoreFields = $settings['ignore'];
		}
	}
	
	public function beforeSave(Model $Model, $options = array()) {
		if (empty($Model->id)) {
			return true;
		}
		
		if ($this->processRevisions) {
			list($diffData, $oldDataDiff) = $this->getDifference($Model);
			if (empty($diffData)) {
				return true;
			}
			
			$role = str_replace(array('_'), array(''), $this->authorPrefix);
			$revisionSlug = $Model->alias.'_'.$Model->id.$this->authorPrefix;
			$authorId = (!empty($this->authorField) && !empty($Model->data[$Model->alias][$this->authorField])) ? $Model->data[$Model->alias][$this->authorField] : 0;
			$revisionInfo = array('id' => array(\Aws\DynamoDb\Enum\Type::S => $revisionSlug),
							'data' => array(\Aws\DynamoDb\Enum\Type::S => $this->maybeSerialize($diffData)),
							'notes' => array(\Aws\DynamoDb\Enum\Type::S => "{$Model->alias} - updated on ".date("d F, Y H:ia")),
							'time' => array(\Aws\DynamoDb\Enum\Type::N => time()),
							'author' => array(\Aws\DynamoDb\Enum\Type::N => $authorId),
							'role' => array(\Aws\DynamoDb\Enum\Type::S => $role)
							);
			$this->saveRevision($revisionInfo);
			if ($this->saveRevisions == false) {
				$Model->data[$Model->alias] = array_merge($Model->data[$Model->alias], $oldDataDiff);
			}
			return true;
		}
		
		return true;
	}
	
	private function initDynamoClient() {
		$AmazonConf = Configure::read('Amazon');
		$this->dynamoDb = DynamoDbClient::factory(array(
				'key'    => $AmazonConf['awsAccessKey'],
				'secret' => $AmazonConf['awsSecretKey'],
				'region' => 'eu-west-1'
		));
		return true;
	}
	
	public function saveRevision($revisionInfo) {
		$this->initDynamoClient();
		try {
			$this->dynamoDb->putItem(array('TableName' => $this->dynamoTable, 'Item' => $revisionInfo));
		} catch (Aws\DynamoDb\Exception\DynamoDbException $e) { pr($e->getMessage()); }
		return true;
	}
	
	public function getRevisionList(Model $Model, $id = '', $options = array()) {
		if (empty($Model->id) && empty($id)) {
			return;
		}
		if (empty($Model->id) && !empty($id)) {
			$Model->id = $id;
		}
		
		$revisions = array();
		$this->initDynamoClient();
		try {
			$revisionSlug = !empty($options['authorPrefix']) ? $Model->alias.'_'.$Model->id.$options['authorPrefix'] : $Model->alias.'_'.$Model->id.$this->authorPrefix;
			$result = $this->dynamoDb->query(array('TableName' => $this->dynamoTable,
										'KeyConditions' => array(
											'id' => array(
												'AttributeValueList' => array(
													array(\Aws\DynamoDb\Enum\Type::S => $revisionSlug)
												),
												'ComparisonOperator' => 'EQ'
											),
											'time' => array(
													'AttributeValueList' => array(
															array(\Aws\DynamoDb\Enum\Type::N => strtotime("-365 days"))
													),
													'ComparisonOperator' => 'GE'
											)
										),
										'Limit' => 25,
										'ScanIndexForward' => false
									));
			
			if (!empty($result['Items'])) {
				foreach ($result['Items'] as $revisionItem) {
					$data = $this->maybeUnserialize($revisionItem['data']['S']);
					$changedFields = !empty($data) ? array_keys($data) : array();
					$changedFieldsStr = !empty($changedFields) ? implode(', ', $changedFields) : '';
					$revisions[] = array('id' => $revisionItem['id']['S'],
										'author' => !empty($revisionItem['author']['S']) ? $revisionItem['author']['S'] : '',
										'time' => $revisionItem['time']['N'],
										'data' => $this->maybeUnserialize($revisionItem['data']['S']),
										'changedfields' => $changedFieldsStr,
										'notes' => $revisionItem['notes']['S'],
										);
				}
			}
		} catch(Aws\DynamoDb\Exception\DynamoDbException $e) {}
		return $revisions;
	}
	
	public function getAllRevisions(Model $Model, $role = '', $options = array()) {
		$this->initDynamoClient();
		$role = !empty($role) ? $role : str_replace(array('_'), array(''), $this->authorPrefix);
		$limit = !empty($options['rowsperpage']) ? $options['rowsperpage'] : 250;
		
		$queryArgs = array('TableName' => $this->dynamoTable,
							'IndexName' => 'role-time-index',
							'KeyConditions' => array(
									'role' => array(
										'AttributeValueList' => array(
												array(\Aws\DynamoDb\Enum\Type::S => $role)
										),
										'ComparisonOperator' => 'EQ'
									),
									'time' => array(
										'AttributeValueList' => array(
												array(\Aws\DynamoDb\Enum\Type::N => strtotime("-365 days"))
										),
										'ComparisonOperator' => 'GE'
									)
							),
							'Limit' => $limit,
							'ScanIndexForward' => false,
						);
		
		if (!empty($options['ExclusiveStartKey'])) {
			$queryArgs['ExclusiveStartKey'] = $options['ExclusiveStartKey'];
		}
		$result = $this->dynamoDb->query($queryArgs);
		
		$revisions = array();
		$LastEvaluatedKey = array();
		if (!empty($result['Items'])) {
			foreach ($result['Items'] as $item) {
				$revisions[] = array('id' => $item['id']['S'],
									'time' => $item['time']['N'],
									'author' => $item['author']['N'],
									'role' => $item['role']['S'],
									'notes' => $item['notes']['S'],
									'data' => !empty($item['data']['S']) ? $this->maybeUnserialize($item['data']['S']) : array()
								);
			}
			$LastEvaluatedKey = !empty($result['LastEvaluatedKey']) ? $result['LastEvaluatedKey'] : array();
		}
		return array($revisions, $LastEvaluatedKey);
	}
	
	public function getRevision(Model $Model, $id = '', $options = array()) {
		$revision = array();
		if (empty($id)) {
			return $revision;
		}
		$this->initDynamoClient();
		
		$compOperator = empty($options['time']) ? 'GE' : 'EQ';
		$time = empty($options['time']) ? strtotime("-365 days") : $options['time'];
		try {
			$revisionSlug = !empty($options['authorPrefix']) ? $Model->alias.'_'.$id.'_'.$options['authorPrefix'] : $Model->alias.'_'.$id.$this->authorPrefix;
			$result = $this->dynamoDb->query(array('TableName' => $this->dynamoTable,
					'KeyConditions' => array(
							'id' => array(
									'AttributeValueList' => array(
											array(\Aws\DynamoDb\Enum\Type::S => $revisionSlug)
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
				$revisionItem = $result['Items'][0];
				$revision['id'] = !empty($revisionItem['id']['S']) ? $revisionItem['id']['S'] : '';
				$revision['notes'] = !empty($revisionItem['notes']['S']) ? $revisionItem['notes']['S'] : '';
				$revision['data'] = !empty($revisionItem['data']['S']) ? $this->maybeUnserialize($revisionItem['data']['S']) : array();
				$revision['author'] = !empty($revisionItem['author']['S']) ? $revisionItem['author']['S'] : array();
			}
			return $revision;
		} catch(Aws\DynamoDb\Exception\DynamoDbException $e) {}
		return $revision;
	}
	
	public function removeRevision(Model $Model, $item = array()) {
		if (empty($item) || empty($item['id']) || empty($item['time'])) {
			return false;
		}
		
		$this->initDynamoClient();
		try {
			$this->dynamoDb->deleteItem(array('TableName' => $this->dynamoTable,
					'Key' => array(
							'id'   => array('S' => $item['id']),
							'time' => array('N' => $item['time'])
					)
			));
		} catch(Aws\DynamoDb\Exception\DynamoDbException $e) {}
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