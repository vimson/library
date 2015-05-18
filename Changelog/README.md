
##CakePHP Changelog - DynamoDB Integration
We can easily store change logs in dynamodb easily using this behavior. Please follow the steps

 + Install Amazon SDK using composer - [Amazon SDK](https://packagist.org/packages/aws/aws-sdk-php)
 + Create a table in DynamoDB and configure table name in ChangelogBehavior
 + Add amazon SDK configuration in core.php
 + Add following code in Model behavior which you want to save change logs

in core.php

```php
Configure::write('Amazon',array('awsAccessKey' => 'XXXXXXXXXXXXXXXXXXX',
						'awsSecretKey' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
							));
```
in Model file add this behavior code

```php
public $actsAs = array(
		'Changelog' => array(
				'ignore' => array('created', 'updated', 'edited_seeder_id', 'seeder_id','ratings','deleted', 'modified'),
				'domain' => 'cms'
			)
	);
```
Some miscellaneous functions for fetching all change logs from dynamo db and getting particular row as follows

```php
list($changelogs, $LastEvaluatedKey) = $this->CourseProvider->getAllChangelogs();
$changelog = $this->CourseProvider->getChangelog(3100, array('time' => '1431931951'));
```
Data item structure of a log in dynamo db is

```php
{
  "id": "cms_CourseProvider_3100",
  "action": "update",
  "after_update": "a:1:{s:4:\"name\";s:40:\"MBA - Human Resources Updated Once again\";}",
  "author": 20,
  "before_update": "a:1:{s:4:\"name\";s:29:\"MBA - Human Resources Updated\";}",
  "domain": "cms",
  "module": "CourseProvider",
  "module_id": 3100,
  "notes": "XXX updated CourseProvider <3100> on May 18, 2015 10:52am",
  "time": 1431931951
}
```

