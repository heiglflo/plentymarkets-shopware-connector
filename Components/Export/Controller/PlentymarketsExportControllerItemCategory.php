<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */


/**
 * PlentymarketsExportControllerItemCategory provides the actual items export functionality. Like the other export
 * entities this class is called in PlentymarketsExportController.
 * The data export takes place based on plentymarkets SOAP-calls.
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
class PlentymarketsExportControllerItemCategory
{

	/**
	 *
	 * @var array
	 */
	protected $mappingShopwareID2PlentyID = array();

	/**
	 *
	 * @var array
	 */
	protected $PLENTY_nameAndLevel2ID = array();

	/**
	 * Build the index and export the missing data to plentymarkets
	 */
	public function run()
	{
		$this->buildPlentyNameAndLevelIndex();
		$this->doExport();
		$this->buildMapping();
	}

	/**
	 * Build an index of the existing data
	 *
	 * @todo language
	 */
	protected function buildPlentyNameAndLevelIndex()
	{
		// Fetch the category catalog from plentymarkets
		$Request_GetItemCategoryCatalogBase = new PlentySoapRequest_GetItemCategoryCatalogBase();
		$Request_GetItemCategoryCatalogBase->Lang = 'de'; // string
		$Request_GetItemCategoryCatalogBase->Level = null; // int
		$Request_GetItemCategoryCatalogBase->Page = 0;

		do
		{
			$Response_GetItemCategoryCatalogBase = PlentymarketsSoapClient::getInstance()->GetItemCategoryCatalogBase($Request_GetItemCategoryCatalogBase);

			if (!$Response_GetItemCategoryCatalogBase->Success)
			{
				throw new PlentymarketsExportException('The item category catalog page »'. $Request_GetItemCategoryCatalogBase->Page .'« could not be retrieved', 2920);
			}

			foreach ($Response_GetItemCategoryCatalogBase->Categories->item as $Category)
			{
				$this->PLENTY_nameAndLevel2ID[$Category->Level][$Category->Name] = $Category->CategoryID;
			}
		}
		while (++$Request_GetItemCategoryCatalogBase->Page < $Response_GetItemCategoryCatalogBase->Pages);
	}

	/**
	 * Export the missing categories to plentymarkets
	 */
	protected function doExport()
	{
		// Only export the categories bond to a shop
		$ShopRepository = Shopware()->Models()->getRepository('Shopware\Models\Shop\Shop');
		$ShopsActive = $ShopRepository->findBy(array('active' => 1), array('default' => 'DESC'));

		$categoryRootIds = array();
		$additionalLanguages = array();

		/** @var Shopware\Models\Shop\Shop $Shop */
		foreach ($ShopsActive as $Shop)
		{
			$categoryRootIds[] = $Shop->getCategory()->getId();

			$language = substr($Shop->getLocale()->getLocale(), 0, 2);
			if (!in_array($language, $additionalLanguages) && $language != 'de')
			{
				$additionalLanguages[] = $language;
			}
		}

		/** @var Shopware\Models\Category\Category $Category */
		foreach (Shopware()->Models()
			->getRepository('Shopware\Models\Category\Category')
			->findBy(array(
			'blog' => 0
		)) as $Category)
		{
			// Root
			if (is_null($Category->getPath()))
			{
				continue;
			}

			//
			$path = array_filter(explode('|', $Category->getPath()));

			// Check whether this category is connected to a shop
			$rootId = end($path);
			if (!in_array($rootId, $categoryRootIds))
			{
				PlentymarketsLogger::getInstance()->error('Export:Initial:Category', 'The item category »'. $Category->getName() . '« with the id »'. $Category->getId() .'« will be skipped (not connected to a shop)', 2921);
				continue;
			}

			// Count the level
			$level = count($path);

			// Looking for a German category
			if (array_key_exists($level, $this->PLENTY_nameAndLevel2ID) && array_key_exists($Category->getName(), $this->PLENTY_nameAndLevel2ID[$level]))
			{
				$categoryIdAdded = $this->PLENTY_nameAndLevel2ID[$level][$Category->getName()];
			}

			else
			{
				$Request_AddItemCategory = new PlentySoapRequest_AddItemCategory();
				$Request_AddItemCategory->Lang = 'de';
				$Request_AddItemCategory->Level = $level;
				$Request_AddItemCategory->MetaDescription = $Category->getMetaDescription();
				$Request_AddItemCategory->MetaKeywords = $Category->getMetaKeywords();
				$Request_AddItemCategory->MetaTitle = $Category->getCmsHeadline();
				$Request_AddItemCategory->Name = $Category->getName();
				$Request_AddItemCategory->Text = $Category->getCmsText();
				$Request_AddItemCategory->Position = $Category->getPosition();

				$Response_AddItemCategory = PlentymarketsSoapClient::getInstance()->AddItemCategory($Request_AddItemCategory);

				if (!$Response_AddItemCategory->Success)
				{
					throw new PlentymarketsExportException('The item category »'. $Request_AddItemCategory->Name .'« could not be exported', 2922);
				}

				$categoryIdAdded = (integer) $Response_AddItemCategory->ResponseMessages->item[0]->SuccessMessages->item[0]->Value;
			}

			// Fill the translation for each category
			if (!empty($additionalLanguages))
			{
				foreach ($additionalLanguages as $additionalLanguage)
				{
					$Request_AddItemCategory = new PlentySoapRequest_AddItemCategory();
					$Request_AddItemCategory->Level = $level;
					$Request_AddItemCategory->CatID = $categoryIdAdded;
					$Request_AddItemCategory->Lang = $additionalLanguage;
					$Request_AddItemCategory->MetaDescription = $Category->getMetaDescription();
					$Request_AddItemCategory->MetaKeywords = $Category->getMetaKeywords();
					$Request_AddItemCategory->MetaTitle = $Category->getCmsHeadline();
					$Request_AddItemCategory->Name = $Category->getName();
					$Request_AddItemCategory->Text = $Category->getCmsText();

					$Response_AddItemCategory = PlentymarketsSoapClient::getInstance()->AddItemCategory($Request_AddItemCategory);

					if (!$Response_AddItemCategory->Success)
					{
						throw new PlentymarketsExportException('The item category »'. $Request_AddItemCategory->Name .'« could not be exported', 2923);
					}
				}
			}

			$this->mappingShopwareID2PlentyID[$Category->getId()] = $categoryIdAdded;
		}
	}

	/**
	 * Generates the mapping
	 */
	protected function buildMapping()
	{
		$Categories = Shopware()->Models()
			->getRepository('Shopware\Models\Category\Category')
			->findBy(array('path' => null));

		/**
		 * @var Shopware\Models\Category\Category $Category
		 * @var Shopware\Models\Category\Category $Child2
		 * @var Shopware\Models\Category\Category $Child3
		 * @var Shopware\Models\Category\Category $Child4
		 * @var Shopware\Models\Category\Category $Child5
		 * @var Shopware\Models\Category\Category $Child6
		 * @var Shopware\Models\Category\Category $Child7
		 */
		foreach ($Categories as $Category)
		{
			// No root category
			if (!$Category->getParentId())
			{
				continue;
			}

			// plentymarkets path
			$path = array();

			//
			$children1 = $Category->getChildren();

			// plentymarkets level 1
			foreach ($children1 as $Child2)
			{
				if ($Child2->getBlog())
				{
					continue;
				}

				$path[0] = $this->mappingShopwareID2PlentyID[$Child2->getId()];

				//
				$children2 = $Child2->getChildren();

				if (count($children2))
				{
					// plentymarkets level 2
					foreach ($children2 as $Child3)
					{
						$path[1] = $this->mappingShopwareID2PlentyID[$Child3->getId()];

						//
						$children3 = $Child3->getChildren();

						if (count($children3))
						{
							// plentymarkets level 3
							foreach ($children3 as $Child4)
							{
								$path[2] = $this->mappingShopwareID2PlentyID[$Child4->getId()];

								//
								$children4 = $Child4->getChildren();

								if (count($children4))
								{
									// plentymarkets level 4
									foreach ($children4 as $Child5)
									{
										$path[3] = $this->mappingShopwareID2PlentyID[$Child5->getId()];

										//
										$children5 = $Child5->getChildren();

										if (count($children5))
										{
											// plentymarkets level 5
											foreach ($children5 as $Child6)
											{
												$path[4] = $this->mappingShopwareID2PlentyID[$Child6->getId()];

												$children6 = $Child6->getChildren();
												if (count($children6))
												{
													foreach ($children6 as $Child7)
													{
														$path[5] = $this->mappingShopwareID2PlentyID[$Child7->getId()];

														PlentymarketsMappingController::addCategory($Child7->getId(), implode(';', $path));
													}
												}
												else
												{
													unset($path[5]);
													PlentymarketsMappingController::addCategory($Child6->getId(), implode(';', $path));
												}

											}
										} // 6
										else
										{
											unset($path[5], $path[4]);
											PlentymarketsMappingController::addCategory($Child5->getId(), implode(';', $path));
										}
									}
								} // 5
								else
								{
									unset($path[5], $path[4], $path[3]);
									PlentymarketsMappingController::addCategory($Child4->getId(), implode(';', $path));
								}
							}
						} // 4
						else
						{
							unset($path[5], $path[4], $path[3], $path[2]);
							PlentymarketsMappingController::addCategory($Child3->getId(), implode(';', $path));
						}
					}
				} // 3
				else
				{
					unset($path[5], $path[4], $path[3], $path[2], $path[1]);
					PlentymarketsMappingController::addCategory($Child2->getId(), implode(';', $path));
				}
			}
		}
	}

	/**
	 * Checks whether the export is finished
	 *
	 * @return boolean
	 */
	public function isFinished()
	{
		return true;
	}
}
