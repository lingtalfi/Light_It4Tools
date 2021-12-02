<?php

namespace Ling\Light_It4Tools\Database;

use Ling\BabyYaml\BabyYamlUtil;
use Ling\Bat\FileSystemTool;
use Ling\Light\ServiceContainer\LightServiceContainerInterface;
use Ling\Light_Database\Service\LightDatabaseService;
use Ling\Light_It4Tools\Exception\LightIt4ToolsException;

/**
 * The It4DbParserTool class.
 */
class It4DbParserTool
{


    /**
     * This property holds the container for this instance.
     * @var LightServiceContainerInterface
     */
    private LightServiceContainerInterface $container;

    /**
     * The db name to use.
     * If null, the current db will be used.
     * @var string|null
     */
    private string|null $dbName;


    /**
     * Builds the It4DbParserTool instance.
     */
    public function __construct()
    {
        $this->dbName = null;
    }

    /**
     * Sets the dbName.
     *
     * @param string $dbName
     */
    public function setDbName(string $dbName)
    {
        $this->dbName = $dbName;
        $this->getDatabaseService()->getMysqlInfoUtil()->changeDatabase($dbName);
    }


    /**
     * Sets the container.
     *
     * @param LightServiceContainerInterface $container
     */
    public function setContainer(LightServiceContainerInterface $container)
    {
        $this->container = $container;
    }


    /**
     * Writes the database structure to the given file.
     *
     *
     * @param string $f
     * @throws \Exception
     */
    public function exportStructure(string $f)
    {
        $_db = $this->getDatabaseService();
        $util = $_db->getMysqlInfoUtil();

        $tables = $util->getTables();

        $s = '';
        foreach ($tables as $table) {
            // assuming mysql 8+
            $row = $_db->fetch("show create table `$table`");
            $statement = $row['Create Table'] ?? null;
            if (null !== $statement) {
                $s .= $statement;
                $s .= PHP_EOL;
                $s .= PHP_EOL;
            }
        }
        FileSystemTool::mkfile($f, $s);
    }


    /**
     * Writes the database structure, using foreign keys, to a customizable output.
     *
     * The output can be chosen via the params.
     * Available params are:
     *
     * - dstType: string(file|dir), whether to output the statements in one big file (file),
     *      or in a directory (dir), in which case one file is created under the given dir, and has the name of
     *      the table with the sql extension.
     *
     * - dstValue: string, the path to the destination file or folder
     *
     *
     *
     * $referenceForeignKeysDir is the reference foreign key file.
     * See the source code for more details.
     *
     * @param string $referenceForeignKeysDir
     * @param array $params
     * @throws \Exception
     */
    public function exportStructureWithForeignKeys(string $referenceForeignKeysDir, array $params = [])
    {

        $dstType = $params['dstType'] ?? null;
        $dstValue = $params['dstValue'] ?? null;

        if (null === $dstValue || null === $dstType) {
            throw new LightIt4ToolsException("You must set the dstType and dstValue params.");
        }


        $_db = $this->getDatabaseService();
        $util = $_db->getMysqlInfoUtil();

        $tables = $util->getTables();


        $statements = [];
        foreach ($tables as $table) {


            $row = $_db->fetch("show create table `$table`");
            $statement = $row['Create Table'] ?? null;
            if (null !== $statement) {


                $lines = explode(PHP_EOL, $statement);
                $engineLine = array_pop($lines);
                if (true === str_contains($engineLine, "ENGINE=InnoDB")) {


                    $columns = $util->getColumnNames($table);
                    $fkeyFile = $referenceForeignKeysDir . "/$table.byml";


                    if (true === file_exists($fkeyFile)) {
                        $arr = BabyYamlUtil::readFile($fkeyFile);
                        foreach ($columns as $column) {
                            $fkey = $arr[$column] ?? null;
                            if (null !== $fkey) {


                                // add comma to the end of the previous line
                                $lastLine = array_pop($lines);
                                $lastLine .= ",";
                                $lines[] = $lastLine;
                                $trailingCommaAdded = true;


                                list($refTable, $refColumn, $comment) = $fkey;
                                $lines[] = "  FOREIGN KEY ($column)";
                                $lines[] = "      REFERENCES $refTable($refColumn)";
                                $lines[] = "      ON DELETE CASCADE";
                            }
                        }
                    }
                } else {
                    throw new \Exception("no 'ENGINE=InnoDB' string found at the end of the create statement, is that a problem?");
                }

                $lines[] = $engineLine . ";"; // put the cap back
                $s = implode(PHP_EOL, $lines);
                $statements[] = $s;


                if ('dir' === $dstType) {
                    $f = $dstValue . "/$table.sql";
                    FileSystemTool::mkfile($f, $s);
                }
            }
        }


        if ('file' === $dstType) {
            FileSystemTool::mkfile($dstValue, implode(PHP_EOL . PHP_EOL, $statements));
        }
    }


    /**
     * Parses the reference foreign key files and creates one file per table which owns at least one foreign key.
     *
     * Note: all table names must be composed solely of simple alphanumeric and underscore chars, otherwise
     * this method won't work as advertised. Same with column names.
     *
     * @param string $fkeysRefFile
     * @param string $dstDir
     */
    public function dispatchFkeys(string $fkeysRefFile, string $dstDir)
    {
        $lines = file($fkeysRefFile, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);


        //--------------------------------------------
        // FIRST CAPTURE (for debugging)
        //--------------------------------------------
        $all = [];
        $table = null;
        foreach ($lines as $line) {
            /**
             * A line can be either:
             * -  a table
             * -  a foreign key
             *
             * This is by definition of the "reference foreign key file".
             *
             */
            $isTable = true;

            // three values below only set when at least one fk is found
            $foreignKey = null;
            $refTable = null;
            $refColumn = null;
            $comment = null;


            // this will work only for databases which table names are composed solely of simple alphanumeric chars.
            if (preg_match('!
--\s*
(?<foreignKey>[a-zA-Z0-9_]+)\s*
(?:
    =>
    \s*(?<refTable>[a-zA-Z0-9_]+)\.(?<refColumn>[a-zA-Z0-9_]+)\s*
    (?:
        \((?<comment>[^)]*)\)
    )?
)?
!x', $line, $match)) {
                $isTable = false;
                $foreignKey = $match["foreignKey"];
                $refTable = $match["refTable"] ?? null;
                $refColumn = $match["refColumn"] ?? null;
                $comment = $match["comment"] ?? null;
            } else {
                $table = trim($line);

            }


            if (true === $isTable) {
                $all[$table] = [];
            } else {
                if (
                    false === empty($refTable) &&
                    false === empty($refColumn)
                ) {
                    $all[$table][$foreignKey] = [
                        $refTable,
                        $refColumn,
                        $comment,
                    ];
                }
            }
        }

        $all = array_filter($all);


        //--------------------------------------------
        // THEN WRITE TO FILES...
        //--------------------------------------------
        foreach ($all as $table => $fkeys) {

            $fileDst = $dstDir . "/$table.byml";
            BabyYamlUtil::writeFile($fkeys, $fileDst);
        }
    }


    /**
     *
     * !! Warning, this function requires a call to the dispatchFkeys method first, otherwise it won't work.
     *
     *
     * Returns an array of table names, composed of two sets.
     * The first set is the given table names.
     * The second set is the ensemble of table names which are related to the first set (usually by the means of a foreign key).
     *
     * The two sets are merged together.
     *
     *
     * Use $noParseTables to pass well known tables with a lot of relationships,
     * but you don't want to show all the relationships. The noParseTable itself will be included,
     * but not its relationships.
     *
     *
     * @param string $foreignKeysDir
     * @param array $tables
     * @param array $noParseTables
     * @return array
     */
    public function getRelatedTablesByTables(string $foreignKeysDir, array $tables, array $noParseTables = []): array
    {
        $alreadyKnownTables = [];
        foreach ($tables as $table) {
            $this->parseRelatedTablesByTable($foreignKeysDir, $table, $alreadyKnownTables, $noParseTables);
        }
        sort($alreadyKnownTables);
        return array_unique($alreadyKnownTables);
    }


    /**
     * Returns the tables starting with the given namespace's prefix and followed by an underscore.
     *
     * If the namespace happens to be a table too, it's also included in the results.
     *
     * @param string $namespace
     * @return array
     */
    public function getTablesByNamespace(string $namespace): array
    {
        $ret = [];
        $tables = $this->getTables();
        foreach ($tables as $table) {
            if (
                $namespace === $table ||
                true === str_starts_with($table, $namespace . "_")
            ) {
                $ret[] = $table;
            }
        }
        return $ret;
    }


    /**
     * Returns the potential namespaces for the database.
     *
     * @return array
     * @throws \Exception
     */
    public function getPotentialNamespaces(): array
    {
        $ret = [];
        $tables = $this->getTables();
        foreach ($tables as $table) {
            $ret[] = explode("_", $table)[0];
        }
        $ret = array_unique($ret);
        return $ret;
    }


    /**
     * Creates a sql file of type create.
     * This file combines the create statements of each of the given table.
     * The createDir should be created using the exportStructureWithForeignKeys method.
     *
     * The goal of this method is to provide a file for MySqlWorkBench to display as a schema.
     * In WorkBench, create a new model, then do File > Import > Reverse Engineer Mysql script...
     *
     * The notFound array is filled with the table for which no create file was found.
     * Each entry is an array containing:
     * - 0: the table name
     * - 1: the createFile path
     *
     *
     *
     * @param string $createDir
     * @param array $tables
     * @param string $dstFile
     * @param array $notFound
     */
    public function clusterize(string $createDir, array $tables, string $dstFile, array &$notFound = [])
    {
        $contents = [];
        $notFound = [];
        $tables = array_unique($tables);
        foreach ($tables as $table) {
            $createFile = $createDir . "/$table.sql";
            if (false === file_exists($createFile)) {
                $notFound[] = [$table, $createFile];
            }
            $contents[] = file_get_contents($createFile);
        }

        FileSystemTool::mkfile($dstFile, implode(PHP_EOL . PHP_EOL, $contents));
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    /**
     * Returns the light database service.
     *
     * @return LightDatabaseService
     * @throws \Exception
     */
    protected function getDatabaseService(): LightDatabaseService
    {
        return $this->container->get("database");
    }


    /**
     * Returns the available tables.
     *
     * @return array
     * @throws \Exception
     */
    protected function getTables(): array
    {
        $_db = $this->getDatabaseService();
        $util = $_db->getMysqlInfoUtil();
        return $util->getTables();
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    /**
     * Accumulates the tables related via a foreign key to the given table.
     *
     *
     * @param string $foreignKeysDir
     * @param string $table
     * @param array $alreadyKnownTables
     * @param array $noParseTables
     */
    private function parseRelatedTablesByTable(string $foreignKeysDir, string $table, array &$alreadyKnownTables = [], array $noParseTables = [])
    {
        if (true === in_array($table, $noParseTables, true)) {
            $alreadyKnownTables[] = $table;
            return;
        }


        if (false === in_array($table, $alreadyKnownTables)) {
            $alreadyKnownTables[] = $table;
            $fkFile = $foreignKeysDir . "/$table.byml";
            if (true === file_exists($fkFile)) {
                $fkeys = BabyYamlUtil::readFile($fkFile);
                foreach ($fkeys as $fkey) {
                    $refTable = $fkey[0];
                    $this->parseRelatedTablesByTable($foreignKeysDir, $refTable, $alreadyKnownTables, $noParseTables);
                }
            }
        }
    }

}