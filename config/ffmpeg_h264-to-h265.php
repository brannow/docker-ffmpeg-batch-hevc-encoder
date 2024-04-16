#!/usr/bin/php
<?php declare(strict_types=1);

$targetDir = trim(getenv('TARGET'));
$targetFormat = trim(getenv('TARGET_FORMAT'));
$encodingDir = '/tmp/encoding';
$skipEncoding = ['hevc', 'av1'];
$fileExtConfig = trim(getenv('ALLOWED_SOURCE_FORMAT'));
$ffmpeg = trim(exec("which ffmpeg"));
$ffprobe = trim(exec("which ffprobe"));
$optionsConfig = trim(getenv('FFMPEG_CONFIG'));
$fileExt = array_map('trim', explode(',', $fileExtConfig));

$options = [];
foreach(explode(' -', $optionsConfig) as $opt) {
   $options[] = '-'.ltrim(trim($opt), '-');
}


var_dump($targetDir, $targetFormat, $encodingDir, $skipEncoding, $ffmpeg, $ffprobe, $fileExt, $options);


if (!file_exists($targetDir) || !file_exists($encodingDir)) {
die('invalid config. abort');
}


function execCommand(string $command, bool $pipeOutput = false): string
{
    $pipe = '';
    if ($pipeOutput)
        $pipe = " 2>&1";
    return strtolower(trim((string)system($command . $pipe)));
}

function getVideoEncoding(SplFileInfo $file): string
{
    global $ffprobe;
    return execCommand($ffprobe . ' -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 "' . $file . '"');
}

function validateFile(SplFileInfo $file): bool
{
    global $ffmpeg;
    return empty(execCommand($ffmpeg . ' -v error -i "'. $file .'" -f null -'));
}

function copyFilePermissions(SplFileInfo $source, SplFileInfo $target): void
{
    $perms = fileperms($source->getRealPath());
    if ($perms !== false) {
        chmod((string)$target, $perms);
    }
}

function encodeFile(SplFileInfo $source, SplFileInfo $target): bool
{
    global $ffmpeg, $options;
    $encodingOptions = ['-i "'. $source .'"']; // input
    array_push($encodingOptions, ...$options);  // encoding options
    $encodingOptions[] = '"'. $target .'"'; // output
    ob_start();
    $encodingOptionsStr = implode(' ', $encodingOptions);
    execCommand($ffmpeg . ' '. $encodingOptionsStr, true);
    $output = ob_get_contents();
    ob_end_clean();
    echo $output;
    if (str_contains($output, "Subtitle codec 94213 is not supported")) {
        echo "Subtitle Error try to convert to str for mkv". PHP_EOL;
        $modifedEncodingOptionsStr = substr_replace($encodingOptionsStr, '-c:s srt ', strpos($encodingOptionsStr, '-c copy') + 8, 0);
        execCommand($ffmpeg . ' '. $modifedEncodingOptionsStr);
    }

     return true;
}

function copyFile(SplFileInfo $source, SplFileInfo $target): bool
{
    return copy((string)$source, (string)$target);
}

function renameFile(SplFileInfo $source, SplFileInfo $target): void
{
    rename((string)$source, (string)$target);
}

function emptyDirectory(string $directory): void
{
    $files = glob(rtrim($directory, '/').'/*');
    foreach($files as $file){ // iterate files
        if(is_file($file)) {
            unlink($file); // delete file
        }
    }
}

function removeFile(SplFileInfo $file): bool
{
    if (is_file((string)$file) && unlink((string)$file)) {
        return !file_exists((string)$file);
    }

    return false;
}

function getDuration(SplFileInfo $file): float
{
    global $ffprobe;
    return (float)trim(execCommand($ffprobe . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "' . $file . '"'));
}

function validateDuration(SplFileInfo $fileA, SplFileInfo $fileB): bool
{
    $dA = getDuration($fileA);
    $dB = getDuration($fileB);
    return ($dA === $dB || (($dA + 100) > $dB && ($dA - 100) < $dB));
}

function getTempEncodingLocation(SplFileInfo $file, string $targetFormat = '', string $prefix = ''): ?SplFileInfo
{
    global $encodingDir;
    $file = $encodingDir . DIRECTORY_SEPARATOR . $prefix . md5((string)$file) . '.' . $targetFormat;
    if (file_exists($file))
        unlink($file);

    return new SplFileInfo($file);
}

function removeEncodingHintsFromBasename(SplFileInfo $file, string $targetFormat = '', string $prefix = ''): SplFileInfo
{
    $permutations = [
        'h264', 'H264', 'h.264', 'H.264', 'h_264', 'H_264', 'h 264', 'H 264',
        'xvid', 'Xvid', 'XVid', 'XVId', 'XVID', 'XviD',
        '__', '()', '[]', '(_)'
    ];
    $basename = str_replace($permutations, '', $file->getBasename($file->getExtension()));
    $basename = str_replace($permutations, '', $basename);
    $basename = str_replace($permutations, '', $basename);
    return new SplFileInfo($file->getPath() . DIRECTORY_SEPARATOR . $prefix . trim($basename, '.') . '.' . $targetFormat);
}

function humanFileSize(int $size, string $unit = ''): string {
  if( (!$unit && $size >= 1<<30) || $unit == "GB")
    return number_format($size/(1<<30),2)."GB";
  if( (!$unit && $size >= 1<<20) || $unit == "MB")
    return number_format($size/(1<<20),2)."MB";
  if( (!$unit && $size >= 1<<10) || $unit == "KB")
    return number_format($size/(1<<10),2)."KB";
  return number_format($size)." bytes";
}

function main(): void
{
    global $targetDir, $fileExt, $skipEncoding, $targetFormat, $encodingDir;

    emptyDirectory($encodingDir);

    $dirIte = new RecursiveDirectoryIterator($targetDir);
    $ite = new RecursiveIteratorIterator($dirIte);

    foreach ($ite as $file) {
        if ($file instanceof SplFileInfo && in_array($file->getExtension(), $fileExt)) {

            echo PHP_EOL . '#################################' . PHP_EOL . PHP_EOL;

            echo 'process: '. $file . PHP_EOL;
            // skip this encoding types
            if (in_array(($encoding = getVideoEncoding($file)), $skipEncoding) || $encoding === '') {
                echo 'file encoding not invalid: ('. $encoding .') ->'. $file . PHP_EOL;
                continue;
            }

            $encodingSourceFile = getTempEncodingLocation($file, $file->getExtension(), 'h264_');
            $encodingTargetFile = getTempEncodingLocation($file, $targetFormat, 'hvec_');
            if (!$encodingTargetFile || !$encodingSourceFile) {
                removeFile($encodingSourceFile);
                removeFile($encodingTargetFile);
                echo 'failed to create tmp file, skip...' . PHP_EOL;
                continue;
            }

            $realTargetFileCopy = removeEncodingHintsFromBasename($file, $targetFormat, '_hvec_');
            $realTargetFile = removeEncodingHintsFromBasename($file, $targetFormat);

            echo 'copy source file: '. $file->getBasename() . ' -> ' . $encodingSourceFile. PHP_EOL;
            if(!copyFile($file, $encodingSourceFile)) {
                echo 'copy failed, skip...' . PHP_EOL;
                continue;
            }

            echo 'start encode'. PHP_EOL;
            if (!encodeFile($encodingSourceFile, $encodingTargetFile)) {
                echo 'encoding failed, skip; remove tmp files' . PHP_EOL;
                removeFile($encodingSourceFile);
                removeFile($encodingTargetFile);
                continue;
            }
            echo 'copy encoded file: '. $encodingTargetFile . ' -> ' . $realTargetFileCopy. PHP_EOL;
            copyFile($encodingTargetFile, $realTargetFileCopy);
            echo 'remove tmp files' . PHP_EOL;
            removeFile($encodingTargetFile);
            removeFile($encodingSourceFile);

            echo 'filesize saved: ' . humanFileSize($file->getSize() - $realTargetFileCopy->getSize()) . PHP_EOL;
            echo 'validate file: ' . $realTargetFileCopy . PHP_EOL;
            if (getVideoEncoding($realTargetFileCopy) === 'hevc' && validateDuration($realTargetFileCopy, $file) && validateFile($realTargetFileCopy)) {
                echo 'file valid, remove original file' . PHP_EOL;
                copyFilePermissions($file, $realTargetFileCopy);
                removeFile($file);
                renameFile($realTargetFileCopy, $realTargetFile);
            } else {
                removeFile($realTargetFileCopy);
                echo 'file invalid, skip & clean up' . PHP_EOL;
            }
        }
    }
}

main();
exit(0);

