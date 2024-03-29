modelCheckTest
==============

Integration test for Kohana ; tests use of models in all controllers

This test verifies statically :
* For each model instantiated in a method (via ORM::factory), that all used columns/relationships exist
* For each model instantiated in a method (via ORM::factory) and created (via create()), that all columns are initialized

This is "quick and dirty", but is does the job (at least, for my use).



How to use :
------------

To use this test, simply add it in you test folder like any other test.
It requires a database (via ORM).

By default, all model instances of all controllers are verified.
To ignore an instance in your controller, add a comment "// @checkForgetMe" on the same line as the instanciation. For example:

```php
$model = ORM::factory('my_model');     // @checkForgetMe
```


Limitations :
-------------

* The model is only tested in the scope of the method: if a model is used outside a method (e.g. in a subcall), it won't be traced. This can be tricky when some columns are initialized in a subcall.
* Variables must not be re-used inside a method. For example, the following example will fail :

```php
        switch ($condition) {
            case "case1":
                $model = ORM::factory('my_model1');
                ...
                break;

            case "case2":
                $model = ORM::factory('my_model2');
                ...
                break;
        }
```

* Inits in different blocks are tricky. For example, with the following example, the analyzer will consider that both columns are initialized :

```php
$model = ORM::factory('my_model');
if ($condition) {
    $model->column1 = "foo";
} else {
    $model->column2 = "bar";
}
$model->create();
```

* The analyzer will fail if using ORM::values :

```php
$user = ORM::factory('user')
        ->values($this->request->post(), array('username','password'))
        ->create();
```