<?php

namespace App\Controller\Admin;

use App\Entity\Stock;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\PercentField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * @extends AbstractCrudController<Stock>
 */
class StockCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Stock::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            // --- SECTION 1: IDENTITY ---
            FormField::addFieldset('Corporate Identity')->setIcon('fas fa-building'),
            
            // Lock the ticker so you don't accidentally break foreign keys or Redis caches
            TextField::new('ticker')
                ->setDisabled($pageName === Crud::PAGE_EDIT),
            
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
            
                ChoiceField::new('systemic_importance')
                ->setChoices([
                    'titan' => 'titan',
                    'base' => 'base',
                    'systemic' => 'systemic',
                    'none' => 'none',
                ]),

            // --- SECTION 2: LIVE DATA (LOCKED) ---
            FormField::addFieldset('Live Market Data (Protected)')->setIcon('fas fa-chart-line')
                ->setHelp('These values are actively managed by the simulation engine and cannot be edited manually.'),
            
            NumberField::new('price')
                ->setDisabled(),
            
            NumberField::new('earningsPerShare', 'EPS')
                ->setDisabled(),
            
            NumberField::new('currentVolatility', 'Current Vol.')
                ->setDisabled()
                ->hideOnIndex(),
                
            PercentField::new('currentRoic', 'Current ROIC')
                ->setStoredAsFractional(true)
                ->setNumDecimals(2)
                ->setDisabled()
                ->hideOnIndex(),

            // --- SECTION 3: PHYSICS CONSTANTS (EDITABLE) ---
            FormField::addFieldset('Simulation Physics (Constants)')->setIcon('fas fa-cogs')
                ->setHelp('Adjusting these will instantly change how the stock behaves in the next engine tick.'),
            
            NumberField::new('sharesOutstanding', 'Shares')
                ->hideOnIndex(),
            NumberField::new('beta'),
            NumberField::new('volatility', 'Baseline Volatility'),
            PercentField::new('baselineRoic', 'Baseline ROIC')
                ->setStoredAsFractional(true)
                ->setNumDecimals(2)->hideOnIndex(),
            PercentField::new('capexRatio', 'CapEx Ratio')
                ->setStoredAsFractional(true)
                ->setNumDecimals(2)->hideOnIndex(),

            // --- SECTION 4: SHOCK ENGINE (EDITABLE) ---
            FormField::addFieldset('Jump Engine (Shocks)')->setIcon('fas fa-bolt')
                ->setHelp('Controls the frequency and severity of sudden market events.'),
            
            NumberField::new('jumpIntensity', 'Intensity')->hideOnIndex(),
            NumberField::new('jumpMean', 'Mean')->hideOnIndex(),
            NumberField::new('jumpVol', 'Volatility')->hideOnIndex(),

            // --- SECTION 5: LORE (EDITABLE) ---
            FormField::addFieldset('Lore & Description')->setIcon('fas fa-book'),
            
            TextareaField::new('description')
                ->hideOnIndex()
                ->renderAsHtml(),
        ];
    }
}