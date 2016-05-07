<?php
/**
 * Alias - make helpers available in views
 *
 * @author David Carr - dave@novaframework.com
 * @version 3.0
 */
namespace Core;

use Core\Config;

/**
 * Aliases - make alias for classes for Views to use without declaring a use element.
 */
class Aliases
{
    public static function init()
    {
        $classes = Config::get('class_aliases');

        if(! is_array($classes)) {
            return;
        }

        foreach ($classes as $classAlias => $className) {
            if (substr($classAlias, 0, 1) != '\\') {
                // This ensures the alias is created in the global namespace.
                $classAlias = '\\' .$classAlias;
            }

            class_alias($className, $classAlias);
        }
    }
}
