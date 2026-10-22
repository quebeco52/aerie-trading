<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Etf;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;

/**
 * @extends AbstractCrudController<Etf>
 */
class EtfCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Etf::class;
    }
}
