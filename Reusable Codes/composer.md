

Using Composer
=====================
Based on local or global installation we can use the following command
```script
php composer.phar somecommand
```	
<br>
```script
composer somecommand
```
The best way to add a new requirement to a composer.json file is with the require command:

```script
composer require somepackage/somepackage:someversion
```
<br>
```script
composer require phpunit/phpunit --dev
```

You can force Composer to optimize the classmap after every installation/update, or in other words, whenever the autoload file is being generated. This is a little bit slower than generating the default autoloader, and slows down as the project grows.

```javascript
{
    "config": {
        "optimize-autoloader": true
    }
}
```
List all installed packages

```script
composer show --installed
```
Another way is forcing it to use --prefer-dist which downloads a stable, packaged version of a project rather than cloning it from the version control system it’s on (much slower). This is on by default, though, so you shouldn’t need to specify it on stable projects. If you want to download the sources, use the --prefer-source flag. 

```script
--prefer-dist
```
