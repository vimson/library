
###How to add a translation table for a belongsTo relation table in CakePHP 2

If you are using cake localization it will do automatically, But when you do separate table for translation you can not achieve that. So better way to do is that bindModel


```
$this->controller->Course->bindModel(array(
				    'hasOne' => array(
				        'CourseProviderTranslation' => array(
				            'foreignKey' => false,
				            'conditions' => array("CourseProviderTranslation.course_provider_id = CourseProvider.id AND CourseProviderTranslation.lang_code = '{$this->controller->langCode}'")
				        )
				    )
				));

```

