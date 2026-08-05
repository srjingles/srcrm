<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'label' => 'Switch view',
        'list' => 'List',
        'board' => 'Board',
    ],

    'opportunities' => [
        'title' => 'Opportunities',
        'actions' => [
            'add' => 'Add Opportunity',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'filters' => [
            'company' => 'Company',
            'contact' => 'Contact',
        ],
        'form' => [
            'name_placeholder' => 'Enter opportunity title',
        ],
        'close_date' => [
            'overdue' => ':date (Overdue)',
            'today' => 'Closes Today',
            'tomorrow' => 'Closes Tomorrow',
        ],
    ],

    'tasks' => [
        'title' => 'Tasks',
        'actions' => [
            'add' => 'Add Task',
            'edit' => 'Edit',
            'delete' => 'Delete',
        ],
        'filters' => [
            'assignee' => 'Assignee',
        ],
        'due_date' => [
            'overdue' => ':date (Overdue)',
            'today' => 'Due Today',
            'tomorrow' => 'Due Tomorrow',
        ],
    ],
];
