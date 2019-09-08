@echo off
TITLE Foxel server software for Minecraft: Pocket Edition
cd /d %~dp0

if exist bin\php\php.exe (
	set PHPRC=""
	set PHP_BINARY=bin\php\php.exe
) else (
	set PHP_BINARY=php
)

if exist PocketMine-MP.phar (
	set FOXEL_FILE=PocketMine-MP.phar
) else if exist Foxel.phar (
    set FOXEL_FILE=Foxel.phar
) else if exist src\pocketmine\PocketMine.php (
    set FOXEL_FILE=src\pocketmine\PocketMine.php
) else (
	echo PocketMine-MP.phar not found
	echo Downloads can be found at https://github.com/FoxelTeam/Foxel/releases
	pause
	exit 1
)

if exist bin\mintty.exe (
	start %PHP_BINARY% %FOXEL_FILE% --enable-ansi %*
) else (
	REM pause on exitcode != 0 so the user can see what went wrong
	%PHP_BINARY% -c bin\php %FOXEL_FILE% %* || pause
)
