<?php
/**
 * @package     Redcore
 * @subpackage  Database
 *
 * @copyright   Copyright (C) 2012 - 2013 redCOMPONENT.com. All rights reserved.
 * @license     GNU General Public License version 2 or later, see LICENSE.
 */

defined('JPATH_REDCORE') or die;

/**
 * Sql Translation class enables table and fields replacement methods
 *
 * @package     Redcore
 * @subpackage  Database
 * @since       1.0
 */
class RDatabaseSqlparserSqltranslation extends RTranslationHelper
{
	/**
	 * Checks if tables inside query have translatable tables and fields and fetch appropriate
	 * value from translations table
	 *
	 * @param   string  $sql     SQL query
	 * @param   string  $prefix  Table prefix
	 *
	 * @return  mixed  Parsed query with added table joins and fields if found
	 */
	public static function parseSelectQuery($sql = '', $prefix = '')
	{
		/**
		 * Basic check for translations, translation will not be inserted if:
		 * If we do not have SELECT anywhere in query
		 * If current language is site default language
		 * If we are in administration
		 */
		if (empty($sql)
			|| !stristr($sql, 'SELECT')
			|| RTranslationHelper::getSiteLanguage() == JFactory::getLanguage()->getTag()
			|| JFactory::getApplication()->isAdmin())
		{
			return null;
		}

		$translationTables = RTranslationHelper::getInstalledTranslationTables();
		$translationTables = RTranslationHelper::removeFromEditForm($translationTables);

		if (empty($translationTables))
		{
			// We do not have any translation table to check
			return null;
		}

		try
		{
			$db = JFactory::getDbo();
			$sqlParser = new RDatabaseSqlparserSqlparser($sql);
			$parsedSql = $sqlParser->parsed;

			if (!empty($parsedSql))
			{
				$foundTables = array();
				$originalTables = array();
				$parsedSqlColumns = null;

				$parsedSql = self::parseTableReplacements($parsedSql, $translationTables, $foundTables, $originalTables);

				if (empty($foundTables))
				{
					// We did not find any table to translate
					return null;
				}

				// Prepare field replacement
				$columns = array();
				$columnFound = false;
				$parsedSqlColumns = $parsedSql;

				// Prepare column replacements
				foreach ($foundTables as $foundTable)
				{
					// Get all columns from that table
					$tableColumns = (array) $translationTables[$foundTable['originalTableName']]->columns;

					if (!empty($tableColumns))
					{
						$selectAllOriginalColumn = $foundTable['alias']['originalName'] . '.*';
						$columns[$selectAllOriginalColumn]['base_expr'] = $selectAllOriginalColumn;
						$columns[$selectAllOriginalColumn]['table'] = $foundTable;

						foreach ($tableColumns as $tableColumn)
						{
							$columns[$db->qn($tableColumn)]['base_expr'] = ''
								. 'COALESCE('
								. $foundTable['alias']['name']
								. '.' . $tableColumn
								. ',' . $foundTable['alias']['originalName']
								. '.' . $tableColumn
								. ')' . ' AS ' . $db->qn($tableColumn);
							$columns[$db->qn($tableColumn)]['table'] = $foundTable;

							if (!empty($columns[$selectAllOriginalColumn]['base_expr']))
							{
								$columns[$selectAllOriginalColumn]['base_expr'] .= ',';
							}

							$columns[$selectAllOriginalColumn]['base_expr'] .= $columns[$db->qn($tableColumn)]['base_expr'];
						}
					}
				}

				$parsedSqlColumns = self::parseColumnReplacements($parsedSqlColumns, $columns, $translationTables, $columnFound);

				// We are only returning parsed SQL if we found at least one column in translation table
				if ($columnFound)
				{
					$sqlCreator = new RDatabaseSqlparserSqlcreator($parsedSqlColumns);

					return $sqlCreator->created;
				}
			}
		}
		catch (Exception $e)
		{
			return null;
		}

		return null;
	}

	/**
	 * Recursive method which go through every array and joins table if we have found the match
	 *
	 * @param   array  $parsedSqlColumns   Parsed SQL in array format
	 * @param   array  $columns            Found replacement tables
	 * @param   array  $translationTables  List of translation tables
	 * @param   array  &$columnFound       Found original tables used for creating unique alias
	 *
	 * @return  array  Parsed query with added table joins if found
	 */
	public static function parseColumnReplacements($parsedSqlColumns, $columns, $translationTables, &$columnFound)
	{
		if (!empty($parsedSqlColumns) && is_array($parsedSqlColumns))
		{
			// Replace all Tables and keys
			foreach ($parsedSqlColumns as $groupColumnsKey => $parsedColumnGroup)
			{
				if (!empty($parsedColumnGroup))
				{
					$filteredGroup = array();

					foreach ($parsedColumnGroup as $tagKey => $tagColumnsValue)
					{
						$column = null;

						if (!empty($tagColumnsValue['expr_type']) && $tagColumnsValue['expr_type'] == 'colref')
						{
							$column = self::getNameIfIncluded($tagColumnsValue['base_expr'], '', $columns, false);

							if (!empty($column))
							{
								$primaryKey = '';

								if (!empty($translationTables[$column['table']['originalTableName']]->primaryKeys))
								{
									foreach ($translationTables[$column['table']['originalTableName']]->primaryKeys as $primaryKeyValue)
									{
										$primaryKey = self::getNameIfIncluded(
											$primaryKeyValue,
											$column['table']['alias']['originalName'],
											array($tagColumnsValue['base_expr']),
											false
										);

										if (empty($primaryKey))
										{
											break;
										}
									}
								}

								// This is primary key so if only this is used in query then we do not need to parse it
								if (empty($primaryKey))
								{
									$columnFound = true;
								}

								if ($groupColumnsKey == 'ORDER' || $groupColumnsKey == 'WHERE' || $groupColumnsKey == 'GROUP')
								{
									if (!empty($primaryKey) || $groupColumnsKey != 'WHERE')//
									{
										$tagColumnsValue['base_expr'] = self::breakColumnAndReplace($tagColumnsValue['base_expr'], $column['table']['alias']['originalName']);
									}
									else
									{
										$tagColumnsValue['base_expr'] = self::breakColumnAndReplace($tagColumnsValue['base_expr'], $column['table']['alias']['name']);
									}
								}
								else
								{
									$tagColumnsValue['base_expr'] = $column['base_expr'];
								}
							}
						}
						elseif (!empty($tagColumnsValue['sub_tree']))
						{
							if (!empty($tagColumnsValue['expr_type']) && $tagColumnsValue['expr_type'] == 'expression')
							{
								foreach ($tagColumnsValue['sub_tree'] as $subKey => $subTree)
								{
									if (!empty($tagColumnsValue['sub_tree'][$subKey]['sub_tree']))
									{
										$tagColumnsValue['sub_tree'][$subKey]['sub_tree'] = self::parseColumnReplacements(
											$tagColumnsValue['sub_tree'][$subKey]['sub_tree'],
											$columns,
											$translationTables,
											$columnFound
										);
									}
								}
							}
							else
							{
								$tagColumnsValue['sub_tree'] = self::parseColumnReplacements($tagColumnsValue['sub_tree'], $columns, $translationTables, $columnFound);
							}
						}

						if (!is_numeric($tagKey))
						{
							$filteredGroup[$tagKey] = $tagColumnsValue;
						}
						else
						{
							$filteredGroup[] = $tagColumnsValue;
						}
					}

					$parsedSqlColumns[$groupColumnsKey] = $filteredGroup;
				}
			}
		}

		return $parsedSqlColumns;
	}

	/**
	 * Recursive method which go through every array and joins table if we have found the match
	 *
	 * @param   array  $parsedSql          Parsed SQL in array format
	 * @param   array  $translationTables  List of translation tables
	 * @param   array  &$foundTables       Found replacement tables
	 * @param   array  &$originalTables    Found original tables used for creating unique alias
	 *
	 * @return  array  Parsed query with added table joins if found
	 */
	public static function parseTableReplacements($parsedSql, $translationTables, &$foundTables, &$originalTables)
	{
		if (!empty($parsedSql) && is_array($parsedSql))
		{
			// Replace all Tables and keys
			foreach ($parsedSql as $groupKey => $parsedGroup)
			{
				if (!empty($parsedGroup))
				{
					$filteredGroup = array();

					foreach ($parsedGroup as $tagKey => $tagValue)
					{
						$tableName = null;
						$newTagValue = null;

						if (!empty($tagValue['expr_type']) && $tagValue['expr_type'] == 'table' && !empty($tagValue['table']))
						{
							$tableName = self::getNameIfIncluded($tagValue['table'], '', $translationTables, true);

							if (!empty($tableName))
							{
								$newTagValue = $tagValue;
								$newTagValue['originalTableName'] = $tableName;
								$newTagValue['table'] = RTranslationTable::getTranslationsTableName($tableName, '');
								$newTagValue['join_type'] = 'LEFT';
								$newTagValue['ref_type'] = 'ON';
								$alias = self::getUniqueAlias($tableName, $originalTables);

								if (!empty($newTagValue['alias']['name']))
								{
									$alias = $newTagValue['alias']['name'];
								}

								$tagValue['alias'] = array(
									'as' => true,
									'name' => $alias,
									'base_expr' => ''
								);

								$newTagValue['alias'] = array(
									'as' => true,
									'name' => self::getUniqueAlias($newTagValue['table'], $foundTables),
									'originalName' => $alias,
									'base_expr' => ''
								);

								$refClause = self::createParserJoinOperand(
									$newTagValue['alias']['name'],
									'=',
									$newTagValue['alias']['originalName'],
									$translationTables[$tableName]
								);
								$newTagValue['ref_clause'] = $refClause;
								$foundTables[$newTagValue['alias']['name']] = $newTagValue;
								$originalTables[$newTagValue['alias']['originalName']] = 1;
							}
						}
						elseif (!empty($tagValue['sub_tree']))
						{
							if (!empty($tagValue['expr_type']) && $tagValue['expr_type'] == 'expression')
							{
								foreach ($tagValue['sub_tree'] as $subKey => $subTree)
								{
									if (!empty($tagValue['sub_tree'][$subKey]['sub_tree']))
									{
										$tagValue['sub_tree'][$subKey]['sub_tree'] = self::parseTableReplacements(
											$tagValue['sub_tree'][$subKey]['sub_tree'],
											$translationTables,
											$foundTables,
											$originalTables
										);
									}
								}
							}
							else
							{
								$tagValue['sub_tree'] = self::parseTableReplacements($tagValue['sub_tree'], $translationTables, $foundTables, $originalTables);
							}
						}

						if (!is_numeric($tagKey))
						{
							$filteredGroup[$tagKey] = $tagValue;
						}
						else
						{
							$filteredGroup[] = $tagValue;
						}

						if (!empty($newTagValue))
						{
							$filteredGroup[] = $newTagValue;
						}
					}

					$parsedSql[$groupKey] = $filteredGroup;
				}
			}
		}

		return $parsedSql;
	}

	/**
	 * Creates unique Alias name not used in existing query
	 *
	 * @param   string  $originalTableName  Original table name which we use for creating alias
	 * @param   array   $foundTables        Currently used tables in the query
	 * @param   int     $counter            Auto increasing number if we already have alias with the same name
	 *
	 * @return  string  Parsed query with added table joins and fields if found
	 */
	public static function getUniqueAlias($originalTableName, $foundTables = array(), $counter = 0)
	{
		$string = str_replace('#__', '', $originalTableName);
		$string .= (string) $counter;

		if (!empty($foundTables[$string]))
		{
			$counter++;

			return self::getUniqueAlias($originalTableName, $foundTables, $counter);
		}

		return $string;
	}

	/**
	 * Breaks column name and replaces alias with the new one
	 *
	 * @param   string  $column       Column Name with or without prefix
	 * @param   string  $replaceWith  Alias name to replace current one
	 *
	 * @return  string  Parsed query with added table joins and fields if found
	 */
	public static function breakColumnAndReplace($column, $replaceWith)
	{
		$column = explode('.', $column);

		if (!empty($column))
		{
			if (count($column) == 1)
			{
				$column[1] = $column[0];
			}

			$column[0] = $replaceWith;
		}

		return implode('.', $column);
	}

	/**
	 * Creates array in sql Parser format, this function adds language filter as well
	 *
	 * @param   string  $newTable     Table alias of new table
	 * @param   string  $operator     Operator of joining tables
	 * @param   string  $oldTable     Alias of original table
	 * @param   object  $tableObject  Alias of original table
	 *
	 * @return  string  Parsed query with added table joins and fields if found
	 */
	public static function createParserJoinOperand($newTable, $operator, $oldTable, $tableObject)
	{
		$db = JFactory::getDbo();
		$refClause = array();

		if (!empty($tableObject->primaryKeys))
		{
			foreach ($tableObject->primaryKeys as $primaryKey)
			{
				$refClause[] = self::createParserElement('colref', $db->qn($newTable) . '.' . $primaryKey);
				$refClause[] = self::createParserElement('operator', $operator);
				$refClause[] = self::createParserElement('colref', $oldTable . '.' . $primaryKey);

				$refClause[] = self::createParserElement('operator', 'AND');
			}
		}

		$refClause[] = self::createParserElement('colref', $db->qn($newTable) . '.rctranslations_language');
		$refClause[] = self::createParserElement('operator', '=');
		$refClause[] = self::createParserElement('colref', $db->q(JFactory::getLanguage()->getTag()));

		$refClause[] = self::createParserElement('operator', 'AND');

		$refClause[] = self::createParserElement('colref', $db->qn($newTable) . '.rctranslations_state');
		$refClause[] = self::createParserElement('operator', '=');
		$refClause[] = self::createParserElement('colref', $db->q('1'));

		return $refClause;
	}

	/**
	 * Creates array in sql Parser format, this function adds language filter as well
	 *
	 * @param   string  $exprType  Expression type
	 * @param   string  $baseExpr  Base expression
	 * @param   bool    $subTree   Sub Tree
	 *
	 * @return  array  Parser Element in array format
	 */
	public static function createParserElement($exprType, $baseExpr, $subTree = false)
	{
		$element = array(
			'expr_type' => $exprType,
			'base_expr' => $baseExpr,
			'sub_tree' => $subTree
		);

		return $element;
	}

	/**
	 * Check for different types of field usage in field list and returns name with alias if present
	 *
	 * @param   string  $field       Field name this can be with or without quotes
	 * @param   string  $tableAlias  Table alias | optional
	 * @param   array   $fieldList   List of fields to check against
	 * @param   bool    $isTable     If we are checking against table string
	 *
	 * @return  mixed  Returns List item if Field name is included in field list
	 */
	public static function getNameIfIncluded($field, $tableAlias = '', $fieldList = array(), $isTable = false)
	{
		// No fields to search for
		if (empty($fieldList))
		{
			return '';
		}

		$fieldParts = explode('.', $field);

		if (count($fieldParts) > 1)
		{
			$alias = $fieldParts[0];

			if (!empty($alias) && strpos($field, '*') !== false)
			{
				// We will search for * field
				$field = $alias . '.*';
			}
		}

		// Check for field inclusion with various cases
		foreach ($fieldList as $fieldFromListQuotes => $fieldFromList)
		{
			if ($isTable)
			{
				switch (self::cleanEscaping($fieldFromListQuotes))
				{
					case self::cleanEscaping($field):
						return $fieldFromListQuotes;
				}
			}
			elseif ($tableAlias == '')
			{
				switch (self::cleanEscaping($fieldFromListQuotes))
				{
					case self::cleanEscaping($field):
					case self::cleanEscaping($fieldFromList['table']['alias']['originalName'] . '.' . $field):
						return $fieldFromList;
				}
			}
			else
			{
				switch (self::cleanEscaping($fieldFromList))
				{
					case self::cleanEscaping($field):
					case self::cleanEscaping($tableAlias . '.' . $field):
						return $fieldFromList;
				}
			}
		}

		return '';
	}

	/**
	 * Check for database escape and remove it
	 *
	 * @param   string  $sql  Sql to check against
	 *
	 * @return  string  Returns true if Field name is included in field list
	 */
	public static function cleanEscaping($sql)
	{
		return str_replace('`', '', trim($sql));
	}
}
