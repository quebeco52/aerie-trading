<?php

namespace App\Controller\Admin;

use App\Entity\Stock;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;

class StockCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Stock::class;
    }


    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('ticker'),
            TextField::new('name'),
            ChoiceField::new('sector')
                ->setChoices([
                    'Information Technology' => 'Information Technology',
                    'Financials' => 'Financials',
                    'Health Care' => 'Health Care',
                    'Consumer Discretionary' => 'Consumer Discretionary',
                    'Consumer Staples' => 'Consumer Staples',
                    'Industrials' => 'Industrials',
                    'Real Estate' => 'Real Estate',
                    'Energy' => 'Energy',
                    'Materials' => 'Materials',
                    'Utilities' => 'Utilities',
                    'Communication Services' => 'Communication Services',
                ]),
            NumberField::new('price'),
            NumberField::new('beta'),
            NumberField::new('volatility'),

            TextareaField::new('description')
                ->hideOnIndex()
                ->renderAsHtml(),
        ];
    }
}
