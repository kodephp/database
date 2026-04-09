<?php

declare(strict_types=1);

namespace Kode\Database\Model\Relation;

use Kode\Database\Model\Model;

/**
 * 属于关联（反向一对一）
 */
class BelongsTo extends Relation
{
    public function getResults(): ?Model
    {
        $query = $this->query();
        $query->where($this->localKey, $this->parent->{$this->foreignKey});
        $result = $query->first();

        if ($result) {
            $instance = new $this->related();
            $instance->setAttributes($result);
            $instance->exists = true;
            return $instance;
        }

        return null;
    }

    public function get(): ?Model
    {
        return $this->getResults();
    }
}
