<?php

namespace LumenApiQueryParser;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use LumenApiQueryParser\Params\Filter;
use LumenApiQueryParser\Params\RequestParamsInterface;
use LumenApiQueryParser\Params\Sort;
use LumenApiQueryParser\Provider\FieldComponentProvider;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use LumenApiQueryParser\Utility\ConnectionParser;

trait BuilderParamsApplierTrait
{

    public function applyParams(Builder $query, RequestParamsInterface $params, ?array $sort_callbacks = null): LengthAwarePaginator
    {

        $connection_and_filters = [];
        $connection_or_filters = [];

        //:: Apply Basic Filters Or Set Connection Filters (whereHas, orWhereHas)
        if ($params->hasFilter()) {
            foreach ($params->getFilters() as $filter) {
                $fieldProvider = new FieldComponentProvider($query, $filter);
                if ($fieldProvider->hasConnections()) {
                    $connectionName = $fieldProvider->getConnectionString();
                    if ($filter->getOperator() === 'has') {
                        $filter->setField($connectionName);
                        $this->applyFilter($query, $filter);
                    } else {
                        $connectionMethod = strtolower($filter->getMethod());
                        $filter->setField($fieldProvider->getField());
                        if ($connectionMethod === 'orwhere') {
                            if (!isset($connection_or_filters[$connectionName])) {
                                $connection_or_filters[$connectionName] = [];
                            }
                            $connection_or_filters[$connectionName][] = $filter;
                        } else {
                            if (!isset($connection_and_filters[$connectionName])) {
                                $connection_and_filters[$connectionName] = [];
                            }
                            $connection_and_filters[$connectionName][] = $filter;
                        }
                    }
                } else {
                    $this->applyFilter($query, $filter, true);
                }
            }
        }

        //:: Apply Basic Sorts And Set Connection Sorts
        $connection_sorts = [];
        if ($params->hasSort()) {
            foreach ($params->getSorts() as $sort) {
                $parser = new ConnectionParser($query, $sort->getField());

                // cache sorts requiring a connection/join
                if ($parser->hasConnections()) {
                    $connectionName = $parser->getConnectionString();
                    if (!isset($connection_sorts[$connectionName])) {
                        $connection_sorts[$connectionName] = [];
                    }
                    $pieces = explode('.', $sort->getField());
                    $field = array_pop($pieces);
                    $connection_sorts[$connectionName][] = [$field, $sort->getDirection()];
                } else {
                    // apply simple sorts
                    $this->applySort($query, $sort);
                }
            }
        }

        //:: Set Connections To Be Included
        $with = [];
        if ($params->hasConnection()) {
            foreach ($params->getConnections() as $connection) {
                $connectionName = $connection->getName();
                $parser = new ConnectionParser($query, $connectionName);
                $connectionName = $parser->getConnectionString();
                $with[] = $connectionName;
            }
        }

        //:: Apply whereHas Connections And Any Connection Sorts
        $where_has_connections = array_unique(
            array_merge(
                ($connection_and_filters ? array_keys($connection_and_filters) : []),
                []
            )
        );

        // handle 'where has' queries
        if (count($where_has_connections)) {
            $query->where(function ($connection_query) use ($where_has_connections, $connection_and_filters) {
                foreach ($where_has_connections as $connectionName) {
                    $filters = isset($connection_and_filters[$connectionName]) ? $connection_and_filters[$connectionName] : [];
                    if (count($filters)) {
                        $connection_query->whereHas($connectionName, function ($q) use ($filters) {
                            foreach ($filters as $filter) {
                                $q->where(function ($inner_query) use ($filter, $q) {

                                    $table_prefix = $inner_query->getModel()->getTable() . '.';

                                    // check if the filter is already prefixes with the table
                                    if (substr($filter->getField(), 0, strlen($table_prefix)) !== $table_prefix) {
                                        $filter->setField(
                                            implode('.', [
                                                $inner_query->getModel()->getTable(),
                                                $filter->getField()
                                            ])
                                        );
                                    } else {
                                        $filter->setField($filter->getField());
                                    }
                                    $this->applyFilter($inner_query, $filter);
                                });
                            }
                        });
                    }
                }
            });
        }

        //:: Apply whereOr Connections And Any Connection Sorts
        $or_where_has_connections = array_diff(array_unique(
            array_merge(
                ($connection_or_filters ? array_keys($connection_or_filters) : []),
                []
            )
        ), $where_has_connections);

        if (count($or_where_has_connections)) {
            $query->orWhere(function ($connection_query) use ($or_where_has_connections, $connection_or_filters) {
                foreach ($or_where_has_connections as $connectionName) {
                    $filters = isset($connection_or_filters[$connectionName]) ? $connection_or_filters[$connectionName] : [];
                    if (count($filters)) {
                        $connection_query->orWhereHas($connectionName, function ($q) use ($filters) {
                            foreach ($filters as $filter) {
                                $q->where(function ($inner_query) use ($filter) {
                                    $table_prefix = $inner_query->getModel()->getTable() . '.';

                                    // check if the filter is already prefixes with the table
                                    if (substr($filter->getField(), 0, strlen($table_prefix)) !== $table_prefix) {
                                        $filter->setField(
                                            implode('.', [
                                                $inner_query->getModel()->getTable(),
                                                $filter->getField()
                                            ])
                                        );
                                    } else {
                                        $filter->setField($filter->getField());
                                    }
                                    $this->applyFilter($inner_query, $filter);
                                });
                            }
                        });
                    }
                }
            });
        }

        // handle sorts that require a connection/join
        if (is_array($connection_sorts) && count($connection_sorts) && is_array($sort_callbacks) && count($sort_callbacks)) {
            // iterate over connection sorts an match to a handler
            foreach ($connection_sorts as $connectionName => $sorts) {
                // check for a handler & call found handler
                if (isset($sort_callbacks[$connectionName]) && is_callable($sort_callbacks[$connectionName])) {
                    $handler = $sort_callbacks[$connectionName];
                    $handler($query, $sorts);
                }
            }
        }

        //:: Apply Connection Includes
        if (count($with)) {
            $query->with($with);
        }

        //:: Apply Pagination
        if ($params->hasPagination()) {
            $pagination = $params->getPagination();
            $query->limit($pagination->getLimit());
            $query->offset($pagination->getPage() * $pagination->getLimit());
            $paginator = $query->paginate(
                $params->getPagination()->getLimit(),
                ['*'],
                'page',
                $params->getPagination()->getPage()
            );
        } else {
            $paginator = $query->paginate(
                $query->count(),
                ['*'],
                'page',
                1
            );
        }

        return $paginator;
    }

    /**
     * 
     * @param Builder $query 
     * @param Filter $filter 
     * @param bool $auto_prefix 
     * @return void 
     */
    protected function applyFilter(Builder $query, Filter $filter, bool $auto_prefix = false): void
    {

        $field = $filter->getField();
        $operator = $filter->getOperator();
        $value = $filter->getValue();
        $method = ($filter->getMethod() ?: 'where');
        $clauseOperator = null;

        // auto-prefix field if not connection
        if ($auto_prefix && strpos($field, '.') === false) {
            $field = $query->getModel()->getTable() . '.' . $field;
        }

        switch ($operator) {
            case 'ct':
                $value = '%' . $value . '%';
                $clauseOperator = 'LIKE';
                break;
            case 'nct':
                $value = '%' . $value . '%';
                $clauseOperator = 'NOT LIKE';
                break;
            case 'sw':
                $value = $value . '%';
                $clauseOperator = 'LIKE';
                break;
            case 'ew':
                $value = '%' . $value;
                $clauseOperator = 'LIKE';
                break;
            case 'eq':
                $clauseOperator = '=';
                break;
            case 'ne':
                $clauseOperator = '!=';
                break;
            case 'gt':
                $clauseOperator = '>';
                break;
            case 'ge':
                $clauseOperator = '>=';
                break;
            case 'lt':
                $clauseOperator = '<';
                break;
            case 'le':
                $clauseOperator = '<=';
                break;
            case 'in':
                break;
            case 'nin':
                break;
            case 'null':
                break;
            case 'nnull':
                break;
            case 'has':
                break;
            default:
                throw new BadRequestHttpException(sprintf('Not allowed operator: %s', $operator));
        }

        if ($operator === 'in') {
            $query->whereIn($field, explode('|', $value));
        } else if ($operator === 'nin') {
            $query->whereNotIn($field, explode('|', $value));
        } else if ($operator === 'null') {
            $query->whereNull($field);
        } else if ($operator === 'nnull') {
            $query->whereNotNull($field);
        } else if ($operator === 'has') {
            $query->has($field);
        } else {
            call_user_func_array([$query, $method], [
                $field,
                $clauseOperator,
                $value
            ]);
        }
    }

    protected function applySort(Builder $query, Sort $sort)
    {
        $query->orderBy($query->getModel()->getTable() . '.' . $sort->getField(), $sort->getDirection());
    }
}
