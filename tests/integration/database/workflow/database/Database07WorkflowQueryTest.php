<?php

namespace RoNoLo\JsonStorage;

class Database07WorkflowQueryTest extends DatabaseWorkflowTestBase
{
    public function testQueryAge20Database()
    {
        $query = new Database\Query($this->db);

        $result = $query
            ->from('something')
            ->find([
                "age" => 99
            ])
            ->execute();

        $this->assertCount(0, $result);
    }
}



