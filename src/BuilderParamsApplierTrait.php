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

    public function applyParams(Builder $query, RequestParamsInterface $params): LengthAwarePaginator
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
                    $this->applyFilter($query, $filter);
                }
            }
        }

        //:: Apply Basic Sorts And Set Connection Sorts
        $connection_sorts = [];
        if ($params->hasSort()) {
            foreach ($params->getSorts() as $sort) {
                $parser = new ConnectionParser($query, $sort->getField(), true);
                if ($parser->hasConnections()) {
                    $connectionName = $parser->getConnectionString();
                    if (!isset($connection_sorts[$connectionName])) {
                        $connection_sorts[$connectionName] = [];
                    }
                    $pieces = explode('.', $sort->getField());
                    $field = array_pop($pieces);
                    $connection_sorts[$connectionName][] = [$field, $sort->getDirection()];
                } else {
                    $this->applySort($query, $sort);
                }
            }
        }

        //:: Set Connections To Be Included
        $with = [];
        if ($params->hasConnection()) {
            foreach ($params->getConnections() as $connection) {
                $connectionName = $connection->getName();
                $parser = new ConnectionParser($query, $connectionName, false);
                $connectionName = $parser->getConnectionString();
                $with[] = $connectionName;
                /**
                 * if (isset($connection_filters[$connectionName])) {
                 * continue;
                 * } else if (isset($connection_sorts[$connectionName])) {
                 * continue;
                 * } else {
                 * $with[] = $connectionName;
                 * }
                 */
            }
        }

        //:: Apply whereHas Connections And Any Connection Sorts
        $where_has_connections = array_unique(
            array_merge(
                ($connection_and_filters ? array_keys($connection_and_filters) : []),
                [] //($connection_sorts ? array_keys($connection_sorts) : [])
            )
        );

        if (count($where_has_connections)) {
            $query->where(function ($connection_query) use ($where_has_connections, $connection_sorts, $connection_and_filters) {
                foreach ($where_has_connections as $connectionName) {
                    $filters = isset($connection_and_filters[$connectionName]) ? $connection_and_filters[$connectionName] : [];
                    $sorts = isset($connection_sorts[$connectionName]) ? $connection_sorts[$connectionName] : [];
                    if (count($filters) || count($sorts)) {
                        $connection_query->whereHas($connectionName, function ($q) use ($filters, $sorts) {
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
                            /*foreach ($sorts as $sort) {

                                // check if the sort is already prefixed with the table
                                $table_prefix = $q->getModel()->getTable() . '.';
                                $sort_suffix = $sort[0];
                                if (substr($sort_suffix, 0, strlen($table_prefix)) !== $table_prefix) {
                                    $sort_suffix = $table_prefix . $sort_suffix;
                                }

                                if (count($sort) == 2) {
                                    if ($sort[1] === 'DESC') {
                                        $q->orderByDesc($sort_suffix);
                                    } else {
                                        $q->orderBy($sort_suffix);
                                    }
                                }
                            }*/
                        });
                    }
                }
            });
        }

        //:: Apply whereOr Connections And Any Connection Sorts
        $or_where_has_connections = array_diff(array_unique(
            array_merge(
                ($connection_or_filters ? array_keys($connection_or_filters) : []),
                [] //($connection_sorts ? array_keys($connection_sorts) : [])
            )
        ), $where_has_connections);

        if (count($or_where_has_connections)) {
            $query->orWhere(function ($connection_query) use ($or_where_has_connections, $connection_sorts, $connection_or_filters) {
                foreach ($or_where_has_connections as $connectionName) {
                    $filters = isset($connection_or_filters[$connectionName]) ? $connection_or_filters[$connectionName] : [];
                    $sorts = isset($connection_sorts[$connectionName]) ? $connection_sorts[$connectionName] : [];
                    if (count($filters) || count($sorts)) {
                        $connection_query->orWhereHas($connectionName, function ($q) use ($filters, $sorts) {
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
                            //                            foreach ($sorts as $sort) {
                            //                                if (count($sort) == 2) {
                            //
                            //                                    // check if the sort is already prefixed with the table
                            //                                    $table_prefix = $q->getModel()->getTable() . '.';
                            //                                    $sort_suffix = $sort[0];
                            //                                    if (substr($sort_suffix, 0, strlen($table_prefix)) !== $table_prefix) {
                            //                                        $sort_suffix = $table_prefix . $sort_suffix;
                            //                                    }
                            //
                            //                                    if ($sort[1] === 'DESC') {
                            //                                        $q->orderByDesc($sort_suffix);
                            //                                    } else {
                            //                                        $q->orderBy($sort_suffix);
                            //                                    }
                            //                                }
                            //                            }
                        });
                    }
                }
            });
        }

        /*// check if we have where statements on the connection
        if (count($where_has_connections)) {
            $query->where(function ($connection_query) use (
                $where_has_connections,
                $connection_sorts,
                $connection_filters,
                $orwhere_filters
            ) {

                foreach ($where_has_connections as $connectionName) {
                    $filters = isset($connection_filters[$connectionName]) ? $connection_filters[$connectionName] : [];
                    $sorts = isset($connection_sorts[$connectionName]) ? $connection_sorts[$connectionName] : [];
                    if (count($filters) || count($sorts)) {

                        $connection_op = 'whereHas';
                        if (isset($orwhere_filters[$connectionName])) {
                            $connection_op = 'orWhereHas';
                        }

                        $connection_query->$connection_op($connectionName, function ($q) use ($filters, $sorts) {
                            foreach ($filters as $filter) {
                                $q->where(function ($q1) use ($filter) {
                                    $this->applyFilter($q1, $filter);
                                });
                                //$this->applyFilter($q, $filter);

                            }
                            foreach ($sorts as $sort) {
                                if (count($sort) == 2) {
                                    if ($sort[1] === 'DESC') {
                                        $q->orderByDesc($sort[0]);
                                    } else {
                                        $q->orderBy($sort[0]);
                                    }
                                }
                            }
                        });
                    }
                }
            });
        }*/

        //:: Apply Connection Includes
        if (count($with)) {
            foreach ($connection_sorts as $connectionName => $sorts) {
                unset($with[array_search($connectionName, $with)]);
                $with[$connectionName] = function ($q) use ($sorts) {
                    foreach ($sorts as $sort) {
                        if (count($sort) == 2) {

                            // check if the sort is already prefixed with the table
                            $table_prefix = $q->getModel()->getTable() . '.';
                            $sort_suffix = $sort[0];
                            if (substr($sort_suffix, 0, strlen($table_prefix)) !== $table_prefix) {
                                $sort_suffix = $table_prefix . $sort_suffix;
                            }

                            if ($sort[1] === 'DESC') {
                                $q->orderByDesc($sort_suffix);
                            } else {
                                $q->orderBy($sort_suffix);
                            }
                        }
                    }
                };
            }
            $query->with($with);
        }
        //var_dump($query->toSql());
        //die();

        //:: Apply Pagination
        if ($params->hasPagination()) {
            $pagination = $params->getPagination();
            $query->limit($pagination->getLimit());
            $query->offset($pagination->getPage() * $pagination->getLimit());
            $paginator = $query->paginate($params->getPagination()->getLimit(), ['*'], 'page', $params->getPagination()->getPage());
        } else {
            $paginator = $query->paginate($query->count(), ['*'], 'page', 1);
        }


        //print_r($query->toSql());

        return $paginator;
    }

    protected function applyFilter(Builder $query, Filter $filter): void
    {

        $field = $filter->getField();
        $operator = $filter->getOperator();
        $value = $filter->getValue();
        $method = ($filter->getMethod() ?: 'where');
        $clauseOperator = null;

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
        $query->orderBy($sort->getField(), $sort->getDirection());
    }
}
