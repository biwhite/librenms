<?php

/**
 * PortsStack.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2024 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\Modules;

use App\Facades\PortCache;
use App\Models\Device;
use App\Models\PortStack;
use App\Observers\ModuleModelObserver;
use Illuminate\Support\Facades\Log;
use LibreNMS\DB\SyncsModels;
use LibreNMS\Interfaces\Data\DataStorageInterface;
use LibreNMS\Interfaces\Module;
use LibreNMS\OS;
use LibreNMS\Polling\ModuleStatus;

class PortsStack implements Module
{
    use SyncsModels;

    /**
     * @inheritDoc
     */
    public function dependencies(): array
    {
        return ['ports'];
    }

    /**
     * @inheritDoc
     */
    public function shouldDiscover(OS $os, ModuleStatus $status): bool
    {
        return $status->isEnabledAndDeviceUp($os->getDevice());
    }

    /**
     * @inheritDoc
     */
    public function shouldPoll(OS $os, ModuleStatus $status): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function discover(OS $os): void
    {
        // First, get stacks from the standard ifStackStatus
        $ifStackData = \SnmpQuery::enumStrings()->walk('IF-MIB::ifStackStatus');
        $portStacks = new \Illuminate\Support\Collection();

        if ($ifStackData->isValid()) {
            $portStacks = $ifStackData->mapTable(function ($data, $lowIfIndex, $highIfIndex = null) use ($os) {
                if ($highIfIndex === null) {
                    Log::debug('Skipping ' . $lowIfIndex . ' due to bad table index from the device');
                    return null;
                }

                if ($lowIfIndex == '0' || $highIfIndex == '0') {
                    return null; // we don't care about the default entries for ports that have stacking enabled
                }

                return new PortStack([
                    'high_ifIndex' => $highIfIndex,
                    'high_port_id' => PortCache::getIdFromIfIndex($highIfIndex, $os->getDevice()),
                    'low_ifIndex' => $lowIfIndex,
                    'low_port_id' => PortCache::getIdFromIfIndex($lowIfIndex, $os->getDevice()),
                    'ifStackStatus' => $data['IF-MIB::ifStackStatus'],
                ]);
            });
        }

        // Second, get stacks from the IEEE8023-LAG-MIB for devices that use it (like OCNOS)
        $dot3adData = \SnmpQuery::walk('IEEE8023-LAG-MIB::dot3adAggPortAttachedAggID');
        if ($dot3adData->isValid()) {
            $dot3adStacks = new \Illuminate\Support\Collection();
            foreach ($dot3adData->pluck() as $member_ifIndex => $aggregator_ifIndex) {
                $port_id_low = PortCache::getIdFromIfIndex($member_ifIndex, $os->getDevice());
                $port_id_high = PortCache::getIdFromIfIndex($aggregator_ifIndex, $os->getDevice());

                if ($port_id_low && $port_id_high) {
                    $dot3adStacks->push(new PortStack([
                        'low_port_id' => $port_id_low,
                        'high_port_id' => $port_id_high,
                        'low_ifIndex' => $member_ifIndex,
                        'high_ifIndex' => $aggregator_ifIndex,
                        'device_id' => $os->getDevice()->device_id,
                        'ifStackStatus' => 'active',
                    ]));
                }
            }
            $portStacks = $portStacks->merge($dot3adStacks);
        }

        if ($portStacks->isEmpty()) {
            return;
        }

        ModuleModelObserver::observe(PortStack::class);
        $this->syncModels($os->getDevice(), 'portsStack', $portStacks->filter());
    }

    /**
     * @inheritDoc
     */
    public function poll(OS $os, DataStorageInterface $datastore): void
    {
        // no polling
    }

    public function dataExists(Device $device): bool
    {
        return $device->portsStack()->exists();
    }

    /**
     * @inheritDoc
     */
    public function cleanup(Device $device): int
    {
        return $device->portsStack()->delete();
    }

    /**
     * @inheritDoc
     */
    public function dump(Device $device, string $type): ?array
    {
        if ($type == 'poller') {
            return null;
        }

        return [
            'ports_stack' => $device->portsStack()
                ->orderBy('high_ifIndex')->orderBy('low_ifIndex')
                ->get(['high_ifIndex', 'low_ifIndex', 'ifStackStatus']),
        ];
    }
}
