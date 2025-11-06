<?php

namespace LumenApiQueryParser\Utility;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ConnectionParser
{

    protected $query;
    protected $value;
    protected $connections;

    protected $is_attribute;

    protected $errors = [];

    public function __construct(Builder $query, $value, bool $is_attribute = false)
    {
        $this->setQuery($query);
        $this->setValue($value);
        $this->is_attribute = $is_attribute;
    }

    /**
     * @return Builder
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * @param Builder $query
     */
    private function setQuery(Builder $query): void
    {
        $this->query = $query;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * @param string $value
     */
    private function setValue($value): void
    {
        $this->value = $value;
    }

    /**
     * @return mixed
     */
    public function getConnections()
    {
        if ($this->connections === null) {
            $this->setConnections($this->parseConnections());
        }
        return $this->connections;
    }

    public static function snakeCaseToCamelCase(string $string): string
    {
        return lcfirst(str_replace('_', '', ucwords($string, '_')));
    }

    public static function connectionsToString(array $connections): string
    {
        return implode('.', $connections);
    }

    public function getConnectionString()
    {
        if (!$this->hasConnections()) {
            return null;
        }
        return self::connectionsToString($this->getConnections());
    }

    /**
     * This function just returns the formatted relationship string without the attribute suffix.
     *  - not able to verify polymorphic relationships in lumen with the utilties in place
     * @return  array
     */
    private function parseConnections(): array
    {
        $context = $this;
        $value = $this->getValue();

        $parts = null;
        if (strpos($value, '.') !== false) {
            $parts = (explode('.', $value) ?: []);
        } else {
            $parts = [$value];
        }
        $parts = array_map(function ($v) use ($context) {
            return $context::snakeCaseToCamelCase($v);
        }, $parts);

        return
            $this->is_attribute ?
            [implode('.', array_slice($parts, 0, count($parts) - 1))] :
            [implode('.', $parts)];
    }


    private function parseConnections___OLD()
    {
        $context = $this;
        $value = $this->getValue();
        $model = $this->getQuery()->getModel();
        $connections = [];
        $parts = null;
        if (strpos($value, '.') !== false) {
            $parts = (explode('.', $value) ?: []);
        } else {
            $parts = [$value];
        }
        $parts = array_map(function ($v) use ($context) {
            return $context::snakeCaseToCamelCase($v);
        }, $parts);
        $temp = $model;
        foreach ($parts as $index => $connection) {
            if (!method_exists($temp, $connection)) {
                if (count($parts) == (int)$index + 1) {
                    break;
                }
                $this->errors[] = 'Method does not exist: ' . $temp . '::' . $connection . '()';
                return [];
            }
            $relation = $temp->$connection();
            if ($relation instanceof Relation) {
                $temp = $relation->getModel();
                $connections[] = $connection;
            }
        }

        return $connections;
    }

    /**
     * @param mixed $connections
     */
    private function setConnections($connections): void
    {
        $this->connections = $connections;
    }

    public function hasConnections()
    {
        $connections = $this->getConnections();
        if ($connections && count($connections)) {
            return true;
        }
        return false;
    }

    public function toArray()
    {
        return [
            'value' => $this->getValue(),
            'connections' => $this->getConnections(),
            'errors' => $this->errors,
        ];
    }
}
