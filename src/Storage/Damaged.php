<?php
namespace TorrentMonitor\Storage;

// A part of a data file that cannot be put right (see Repair). The message names the part.
final class Damaged extends \Exception
{
}
