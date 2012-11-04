<?php
defined('SYSPATH') or die('No direct access allowed!');

function find_all_files($dir)
{
    $root = scandir($dir);
    foreach ($root as $value) {
        if ($value === '.' || $value === '..') {
            continue;
        }
        if (is_file("$dir/$value")) {
            $result[] = "$dir/$value";
            continue;
        }
        foreach (find_all_files("$dir/$value") as $value) {
            $result[] = $value;
        }
    }
    return $result;
}

/**
 * Static test of all use of models
 */
class modelCheckTest extends Kohana_UnitTest_TestCase
{

    /**
     * Columns that are ignored on a creation
     * These columns, if not assigned on a creation, will NOT generate a test failure
     * @var array
     */
    private $ignoredColumns = array("id", "date_created");
    
    
    
    //// Variable internally used, do not modify
    
    /**
     * Root dir of the controllers
     * @var string 
     */
    private $docbase;
    /**
     * Complete path of the current controller being processed
     * @var string
     */
    private $file;
    
    /**
     * Content of the current controller being processed
     * @var array
     */
    private $content = array();
    /**
     * List of methods of the current controller
     * @var array
     */
    private $methods = array();
    /**
     * List of models of the current controller
     * @var array
     */
    private $models = array();

    /**
     * Treat all models to find errors
     */
    private function treatModels()
    {
        foreach ($this->models as &$model) {
            // Transforming data into a better structure
            $values = array_pad(array(), sizeof($model['columns']), false);
            $columns = array_combine($model['columns'], $values);
            if (sizeof($model['relationships']) > 0) {
                $values2 = array_pad(array(), sizeof($model['relationships']),
                        false);
                $relationships = array_combine($model['relationships'], $values2);
            } else {
                $relationships = array();
            }

            for ($i = $model['start']; $i < $model['end']; $i++) {
                $matches = array();
                
                // Determining whether the model is created, updated or simply used
                if (preg_match('/\$' . $model['var'] . '->update\(/',
                                $this->content[$i], $matches) > 0) {
                    $model['update'] = true;
                } else if (preg_match('/\$' . $model['var'] . '->create\(/',
                                $this->content[$i], $matches) > 0) {
                    $model['insert'] = true;
                }
                
                // A column is assigned
                if (preg_match_all('/\$' . $model['var'] . '->([a-zA-Z_]+)\s*=/',
                                $this->content[$i], $matches) > 0) {
                    for ($j = 0; $j < sizeof($matches[0]); $j++) {
                        // Checking whether the column exists or not
                        if (!array_key_exists($matches[1][$j], $columns)) {
                            $this->fail("In controller " . str_replace($this->docbase,
                                            "", $this->file) . ":" . ($i+1) . ": column " . $matches[1][$j] . " does not exist in model " . $model['model']);
                        }
                        $columns[$matches[1][$j]] = true;
                    }
                // A column or a relationship is used
                } else if (preg_match_all('/\$' . $model['var'] . '->([a-zA-Z_]+)[-\);]/',
                                $this->content[$i], $matches) > 0) {
                    for ($j = 0; $j < sizeof($matches[0]); $j++) {
                        // Checking wheter the column/relationship exists or not
                        if (!array_key_exists($matches[1][$j], $columns) && !array_key_exists($matches[1][$j],
                                        $relationships)) {
                            $this->fail("In controller " . str_replace($this->docbase,
                                            "", $this->file) . ":" . ($i+1) . ": column/relationship " . $matches[1][$j] . " does not exist in model " . $model['model']);
                        }
                    }
                }
            }

            // On creation, all columns must be assigned
            if ($model['insert']) {
                foreach ($columns as $key => $value) {
                    // Skip "special" columns, as they are assigned automatically
                    if (!in_array($key, $this->ignoredColumns) && !$value) {
                        $this->fail("In controller " . str_replace($this->docbase,
                                        "", $this->file) . ":" . ($model['start']+1) . ": column " . $key . " of model " . $model['model'] . " is not initialized");
                    }
                }
            }
        }
    }

    /**
     * Finds all the models in the controller
     */
    private function findModels()
    {
        $this->models = array();
        foreach ($this->methods as $func) {
            $matches = array();
            for ($i = $func['start']; $i <= $func['end']; $i++) {
                // A model is assigned and not ignored with tag @checkForgetMe
                if (preg_match_all('/\$([a-zA-Z_]+)\s*=\s*ORM::factory\([\'"]([a-zA-Z_]+)[\'"]/',
                                $this->content[$i], $matches) > 0
                        && strpos($this->content[$i], "@checkForgetMe") === FALSE) {
                    for ($j = 0; $j < sizeof($matches[0]); $j++) {
                        $orm = ORM::factory($matches[2][$j]);
                        
                        // Create data structure
                        $this->models[] = array(
                            "var" => $matches[1][$j],
                            "model" => $matches[2][$j],
                            "start" => $i,
                            "end" => $func['end'],
                            "columns" => array_keys($orm->table_columns()),
                            "relationships" => array_merge(array_keys($orm->belongs_to()),
                                    array_keys($orm->has_many()),
                                    array_keys($orm->has_one())),
                            "update" => false,
                            "insert" => false
                        );
                    }
                }
            }
        }
    }

    /**
     *  Finds all the methods in the controller
     */
    private function findMethods()
    {
        $this->methods = array();
        $length = sizeof($this->content);

        $current = array();
        $in_func = false;
        $nb = 0;

        for ($i = 0; $i < $length; $i++) {
            if (strpos($this->content[$i], "function ") !== false) {
                $current['start'] = $i + 2;
                $in_func = true;
                $nb = 1;
                $i++;
            } else {
                if (strpos($this->content[$i], "{") !== false) {
                    $nb++;
                }
                if (strpos($this->content[$i], "}") !== false) {
                    $nb--;
                    if ($nb == 0) {
                        $current['end'] = $i - 1;
                        $in_func = false;
                        $this->methods[] = $current;
                    }
                }
            }
        }
    }

    /**
     * Treats a controller
     */
    private function treatController()
    {
        $this->content = explode("\n", file_get_contents($this->file));
        $this->findMethods();
        $this->findModels();
        $this->treatModels();
    }

    /**
     * Tests all the controllers
     * @coversNothing
     */
    public function testControllers()
    {
        $this->docbase = APPPATH . 'classes' . DIRECTORY_SEPARATOR . 'controller';
        $files = find_all_files($this->docbase);
        foreach ($files as $file) {
            if (substr($file, -strlen(3)) === 'php') {
                $this->file = $file;
                $this->treatController();
            }
        }
    }
}