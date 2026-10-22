<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Controller\Admin\StockCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/** What the admin forms can edit, and that an edit there takes effect. */
class AdminFieldsTest extends TestCase
{
    /** The login refuses an unverified account, and an admin-created one has no email link to follow. */
    public function testAnAdministratorCanCreateAnAccountThatCanSignIn(): void
    {
        $users = new UserCrudController($this->createStub(UserPasswordHasherInterface::class));

        $this->assertContains('isVerified', $this->properties($users->configureFields(Crud::PAGE_NEW)));
        $this->assertContains('username', $this->properties($users->configureFields(Crud::PAGE_NEW)));
        $this->assertTrue($users->createEntity(User::class)->isVerified());
    }

    /** The stock page reads the lore, so a description field would accept edits that never show. */
    public function testTheStockFormOffersNoDescriptionThePageWouldIgnore(): void
    {
        $this->assertNotContains('description', $this->properties((new StockCrudController())->configureFields(Crud::PAGE_EDIT)));
    }

    /**
     * @param iterable<FieldInterface|string> $fields
     * @return list<string>
     */
    private function properties(iterable $fields): array
    {
        $properties = [];
        foreach ($fields as $field) {
            if ($field instanceof FieldInterface) {
                $properties[] = $field->getAsDto()->getProperty();
            }
        }

        return $properties;
    }
}
