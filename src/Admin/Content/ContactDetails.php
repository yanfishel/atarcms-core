<?php

declare(strict_types=1);

namespace AtarCms\Admin\Content;

/**
 * The contact details on the contact page's entry form (config content.contact_page; SettingsForm::contactFields()):
 * their values as the inputs show them and their errors, keyed by the input names (phone, address, social_vk…).
 */
final readonly class ContactDetails
{
    /**
     * @param array<string, string> $values
     * @param array<string, string> $errors
     * @param array<string, array<string, int|float|string>> $params
     */
    public function __construct(
        public array $values,
        public array $errors = [],
        public array $params = [],
    ) {}
}
