<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * @extends AbstractCrudController<User>
 */
class UserCrudController extends AbstractCrudController
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    /** An account an administrator creates starts verified: there is no email link for it to have followed. */
    public function createEntity(string $entityFqcn): User
    {
        return (new User())->setIsVerified(true);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            EmailField::new('email'),

            TextField::new('username'),

            // The login refuses an unverified account, so an administrator needs this to let one in.
            BooleanField::new('isVerified', 'Email verified'),

            TextField::new('plainPassword', 'New Password')
                ->onlyOnForms()
                ->setFormType(PasswordType::class)
                ->setRequired($pageName === Crud::PAGE_NEW),

            ChoiceField::new('roles')
                ->setChoices([
                    'Standard User' => 'ROLE_USER',
                    'Administrator' => 'ROLE_ADMIN',
                ])
                ->allowMultipleChoices(),

            NumberField::new('cashBalance', 'Cash Balance')
                ->setNumDecimals(2)
                ->hideOnIndex(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }

    private function hashPassword(mixed $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if ($user->getPlainPassword()) {
            $hashedPassword = $this->passwordHasher->hashPassword($user, $user->getPlainPassword());
            $user->setPassword($hashedPassword);

            $user->setPlainPassword(null);
        }
    }
}
