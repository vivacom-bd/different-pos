<?php

declare(strict_types=1);

/* Online register: API errors. */
return [
    'errors' => [
        'generic' => 'The sale could not be completed.',
        'discount_cap_exceeded' => 'The discount is above your limit. Ask a manager to approve it.',
        'credit_limit_exceeded' => 'This due is not allowed: a walk-in cannot owe, and a customer cannot go above the credit limit without a manager.',
        'insufficient_stock' => 'Not enough stock at this branch.',
        'below_cost_sale' => 'This price is below cost. Ask a manager to approve it.',
        'price_override_not_allowed' => 'You may not change the price. Ask a manager to approve it.',
        'backdate_not_allowed' => 'This sale date is not allowed.',
        'invalid_tender' => 'The payment is not valid.',
        'invalid_sale_document' => 'The bill is not valid.',
        'invalid_quantity' => 'A quantity is not valid.',
        'invalid_money' => 'An amount is not valid.',
        'member_pin_required' => 'This customer must enter their PIN (or a manager must override) before the sale completes.',
        'customer_required' => 'Attach a customer to this sale before completing it.',
    ],
    'approval' => [
        'invalid' => 'Wrong login or password.',
        'not_allowed' => ':name may not approve this.',
        'expired' => 'The manager approval expired. Ask again.',
    ],
];
