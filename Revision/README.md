##CakePHP Revision Behavior
We can easily configure revisions saved using CakePHP models in DynamoDB. Please follow the steps

 + Install Amazon SDK using composer - [Amazon SDK](https://packagist.org/packages/aws/aws-sdk-php)
 + Create a table in DynamoDB and configure table name in RevisionBehavior
 + Add amazon SDK configuration in core.php
 + Add following code in Model behavior which you want to save revisions

in core.php

```php
Configure::write('Amazon',array('awsAccessKey' => 'XXXXXXXXXXXXXXXXXXX',
						'awsSecretKey' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
							));
```
in Model file add this behavior code

```php
public $actsAs = array(
		'Revision' => array(
				'ignore' => array('created', 'updated', 'edited_seeder_id', 'seeder_id','ratings','deleted'),
				'process_revisions' => true,
				'save_revisions' => false,
				'author_prefix' => 'Provider',
				'author_field' => 'edited_client_id'
			)
	);
```