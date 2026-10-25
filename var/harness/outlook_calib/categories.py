"""Industry groupings. CATEGORIES: the first-pass grouping fit_us/fit_momentum test; FINAL: the recommended
categories with their outlook settings (Farm Products sits in staple: 60% of CROP is processing volume)."""

CATEGORIES = {
 "settled": [
  "Grocery Stores",
  "Utilities - Regulated Electric",
  "Utilities - Regulated Water",
  "Waste Management",
  "Packaged Foods",
  "Household & Personal Products",
  "Beverages - Wineries & Distilleries",
  "Healthcare Plans",
  "Medical Care Facilities",
  "REIT - Residential",
  "REIT - Healthcare Facilities",
  "Legal Services",
  "Security & Protection Services",
  "Tobacco"
 ],
 "industrial": [
  "Building Products & Equipment",
  "Engineering & Construction",
  "Industrial Distribution",
  "Specialty Industrial Machinery",
  "Farm & Heavy Construction Machinery",
  "Tools & Accessories",
  "Electrical Equipment & Parts",
  "Chemicals",
  "Steel",
  "Integrated Freight & Logistics",
  "Railroads",
  "Consulting Services",
  "Advertising Agencies",
  "Telecom Services",
  "Marine Ports & Services",
  "REIT - Industrial",
  "REIT - Office",
  "Residential Construction"
 ],
 "discretionary": [
  "Airlines",
  "Resorts & Casinos",
  "Luxury Goods",
  "Apparel Manufacturing",
  "Broadcasting",
  "Restaurants",
  "Education & Training Services"
 ],
 "frontier": [
  "Semiconductors",
  "Computer Hardware",
  "Communication Equipment",
  "Internet Content & Information",
  "Internet Retail",
  "Drug Manufacturers - General",
  "Aerospace & Defense",
  "Space Systems",
  "Nuclear Power Equipment",
  "Financial Data & Stock Exchanges",
  "Auto Manufacturers"
 ],
 "commodity": [
  "Copper",
  "Gold",
  "Oil & Gas E&P",
  "Oil & Gas Refining & Marketing",
  "Farm Products",
  "Marine Shipping",
  "Utilities - Independent Power Producers",
  "Merchant Houses"
 ]
}

FINAL = {
 "staple": {
  "drift_vol": 0.025,
  "learning_half_life": 0.5,
  "industries": [
   "Grocery Stores",
   "Packaged Foods",
   "Household & Personal Products",
   "Healthcare Plans",
   "Medical Care Facilities",
   "REIT - Healthcare Facilities",
   "REIT - Residential",
   "Waste Management",
   "Education & Training Services",
   "Restaurants",
   "Integrated Freight & Logistics",
   "Marine Ports & Services",
   "Farm Products"
  ]
 },
 "steady": {
  "drift_vol": 0.04,
  "learning_half_life": 0.5,
  "industries": [
   "Airlines",
   "Apparel Manufacturing",
   "Beverages - Wineries & Distilleries",
   "Broadcasting",
   "Consulting Services",
   "Financial Data & Stock Exchanges",
   "Legal Services",
   "REIT - Industrial",
   "Resorts & Casinos",
   "Security & Protection Services",
   "Telecom Services",
   "Utilities - Independent Power Producers"
  ]
 },
 "shifting": {
  "drift_vol": 0.06,
  "learning_half_life": 1.0,
  "industries": [
   "Advertising Agencies",
   "Building Products & Equipment",
   "Chemicals",
   "Drug Manufacturers - General",
   "Electrical Equipment & Parts",
   "Engineering & Construction",
   "Industrial Distribution",
   "Internet Retail",
   "Luxury Goods",
   "Marine Shipping",
   "REIT - Office",
   "Railroads",
   "Specialty Industrial Machinery",
   "Steel",
   "Tobacco",
   "Tools & Accessories"
  ]
 },
 "volatile": {
  "drift_vol": 0.09,
  "learning_half_life": 0.75,
  "industries": [
   "Aerospace & Defense",
   "Auto Manufacturers",
   "Communication Equipment",
   "Computer Hardware",
   "Farm & Heavy Construction Machinery",
   "Internet Content & Information",
   "Nuclear Power Equipment",
   "Residential Construction",
   "Semiconductors",
   "Space Systems"
  ]
 },
 "world_commodity": {
  "drift_vol": 0.0,
  "learning_half_life": 0.5,
  "industries": [
   "Copper",
   "Gold",
   "Oil & Gas E&P",
   "Oil & Gas Refining & Marketing"
  ]
 },
 "inert": {
  "drift_vol": 0.0,
  "learning_half_life": 0.5,
  "industries": [
   "Conglomerates",
   "Investment Companies",
   "Merchant Houses",
   "Utilities - Regulated Electric",
   "Utilities - Regulated Water"
  ]
 }
}
