<?php

declare(strict_types=1);

/**
 * NOTICE OF LICENSE.
 *
 * UNIT3D Community Edition is open-sourced software licensed under the GNU Affero General Public License v3.0
 * The details is bundled with this project in the file LICENSE.txt.
 *
 * @project    UNIT3D Community Edition
 *
 * @author     HDVinnie <hdinnovations@protonmail.com>
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html/ GNU Affero General Public License v3.0
 * @credits    Rhilip <https://github.com/Rhilip> Roardom <roardom@protonmail.com>
 */

namespace App\Http\Controllers;

use App\Exceptions\TrackerException;
use App\Helpers\Bencode;
use App\Jobs\ProcessAnnounce;
use App\Models\Group;
use App\Models\Peer;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AnnounceController extends Controller
{
    // Torrent Moderation Codes
    protected const PENDING = 0;
    protected const REJECTED = 2;
    protected const POSTPONED = 3;

    // Announce Intervals
    private const MIN = 3_600;
    private const MAX = 5_400;

    // Port Blacklist
    private const BLACK_PORTS = [
        // SSH Port
        22,
        // DNS queries
        53,
        // Hyper Text Transfer Protocol (HTTP) - port used for web traffic
        80,
        81,
        8080,
        8081,
        // 	Direct Connect Hub (unofficial)
        411,
        412,
        413,
        // HTTPS / SSL - encrypted web traffic, also used for VPN tunnels over HTTPS.
        443,
        // Kazaa - peer-to-peer file sharing, some known vulnerabilities, and at least one worm (Benjamin) targeting it.
        1214,
        // IANA registered for Microsoft WBT Server, used for Windows Remote Desktop and Remote Assistance connections
        3389,
        // eDonkey 2000 P2P file sharing service. http://www.edonkey2000.com/
        4662,
        // Gnutella (FrostWire, Limewire, Shareaza, etc.), BearShare file sharing app
        6346,
        6347,
        // Port used by p2p software, such as WinMX, Napster.
        6699,
    ];

    /**
     * Announce Code.
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function index(Request $request, string $passkey): ?Response
    {
        $repDict = null;

        try {
            // Check client.
            $this->checkClient($request);

            // Check passkey.
            $this->checkPasskey($passkey);

            // Check and then get Announce queries.
            $queries = $this->checkAnnounceFields($request);

            // Check user via supplied passkey.
            $user = $this->checkUser($passkey, $queries);

            // Get Torrent Info Array from queries and judge if user can reach it.
            $torrent = $this->checkTorrent($queries['info_hash']);

            // Check if a user is announcing a torrent as completed but no peer is in db.
            $this->checkPeer($torrent, $queries, $user);

            // Lock Min Announce Interval.
            if (\config('announce.min_interval.enabled')) {
                $this->checkMinInterval($torrent, $queries, $user);
            }

            // Check User Max Connections Per Torrent.
            $this->checkMaxConnections($torrent, $user);

            // Check Download Slots.
            if (\config('announce.slots_system.enabled')) {
                $this->checkDownloadSlots($queries, $user);
            }

            // Generate A Response For The Torrent Clent.
            $repDict = $this->generateSuccessAnnounceResponse($queries, $torrent, $user);

            // Process Annnounce Job.
            $this->processAnnounceJob($queries, $user, $torrent);
        } catch (TrackerException $exception) {
            $repDict = $this->generateFailedAnnounceResponse($exception);
        } finally {
            return $this->sendFinalAnnounceResponse($repDict);
        }
    }

    /**
     * Check Client Is Valid.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    protected function checkClient(Request $request): void
    {
        // Miss Header User-Agent is not allowed.
        \throw_if(! $request->header('User-Agent'), new TrackerException(120));

        // Block Other Browser, Crawler (May Cheater or Faker Client) by check Requests headers
        \throw_if($request->header('accept-language') || $request->header('referer')
            || $request->header('accept-charset')

            /**
             * This header check may block Non-bittorrent client `Aria2` to access tracker,
             * Because they always add this header which other clients don't have.
             *
             * @see https://blog.rhilip.info/archives/1010/ ( in Chinese )
             */
            || $request->header('want-digest'), new TrackerException(122));

        $userAgent = $request->header('User-Agent');

        // Should also block User-Agent strings that are too long. (For Database reasons)
        \throw_if(\strlen((string) $userAgent) > 64, new TrackerException(123));

        // Block Browser by checking the User-Agent
        \throw_if(\preg_match('/(Mozilla|Browser|Chrome|Safari|AppleWebKit|Opera|Links|Lynx|Bot|Unknown)/i',
            (string) $userAgent), new TrackerException(121));

        // Block Blacklisted Clients
        \throw_if(\in_array($request->header('User-Agent'), \config('client-blacklist.clients')),
            new TrackerException(128, [':ua' => $request->header('User-Agent')]));
    }

    /**
     * Check Passkey Exist and Valid.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    protected function checkPasskey($passkey): void
    {
        // If Passkey Is Not Provided Return Error to Client
        \throw_if($passkey === null, new TrackerException(130, [':attribute' => 'passkey']));

        // If Passkey Length Is Wrong
        \throw_if(\strlen((string) $passkey) !== 32,
            new TrackerException(132, [':attribute' => 'passkey', ':rule' => 32]));

        // If Passkey Format Is Wrong
        \throw_if(\strspn(\strtolower($passkey), 'abcdef0123456789') !== 32,
            new TrackerException(131, [':attribute' => 'passkey', ':reason' => 'Passkey format is incorrect']));
    }

    /**
     * Extract and validate Announce fields.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    private function checkAnnounceFields(Request $request): array
    {
        $queries = [
            'timestamp' => $request->server->get('REQUEST_TIME_FLOAT'),
        ];

        // Part.1 Extract required announce fields
        foreach (['info_hash', 'peer_id', 'port', 'uploaded', 'downloaded', 'left'] as $item) {
            $itemData = $request->query->get($item);
            if (! \is_null($itemData)) {
                $queries[$item] = $itemData;
            } else {
                throw new TrackerException(130, [':attribute' => $item]);
            }
        }

        foreach (['info_hash', 'peer_id'] as $item) {
            \throw_if(\strlen((string) $queries[$item]) !== 20,
                new TrackerException(133, [':attribute' => $item, ':rule' => 20]));
        }

        foreach (['uploaded', 'downloaded', 'left'] as $item) {
            $itemData = $queries[$item];
            \throw_if(! \is_numeric($itemData) || $itemData < 0,
                new TrackerException(134, [':attribute' => $item]));
        }

        // Part.2 Extract optional announce fields
        foreach ([
            'event'   => '',
            'numwant' => 25,
            'corrupt' => 0,
            'key'     => '',
        ] as $item => $value) {
            $queries[$item] = $request->query->get($item, $value);
        }

        foreach (['numwant', 'corrupt'] as $item) {
            \throw_if(! \is_numeric($queries[$item]) || $queries[$item] < 0,
                new TrackerException(134, [':attribute' => $item]));
        }

        \throw_if(! \in_array(\strtolower($queries['event']), ['started', 'completed', 'stopped', 'paused', '']),
            new TrackerException(136, [':event' => \strtolower($queries['event'])]));

        // Part.3 check Port is Valid and Allowed
        /**
         * Normally , the port must in 1 - 65535 , that is ( $port > 0 && $port < 0xffff )
         * However, in some case , When `&event=stopped` the port may set to 0.
         */
        \throw_if($queries['port'] === 0 && \strtolower($queries['event']) !== 'stopped',
            new TrackerException(137, [':event' => \strtolower($queries['event'])]));

        \throw_if(! \is_numeric($queries['port']) || $queries['port'] < 0 || $queries['port'] > 0xFFFF
            || \in_array($queries['port'],
                self::BLACK_PORTS,
                true), new TrackerException(135, [':port' => $queries['port']]));

        // Part.4 Get User Ip Address
        $queries['ip-address'] = $request->getClientIp();

        // Part.5 Get Users Agent
        $queries['user-agent'] = $request->headers->get('user-agent');

        // Part.6 bin2hex info_hash
        $queries['info_hash'] = \bin2hex($queries['info_hash']);

        // Part.7 bin2hex peer_id
        $queries['peer_id'] = \bin2hex($queries['peer_id']);

        return $queries;
    }

    /**
     * Get User Via Validated Passkey.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    protected function checkUser($passkey, $queries): object
    {
        // Caached System Required Groups
        $bannedGroup = \cache()->rememberForever('banned_group',
            fn () => Group::where('slug', '=', 'banned')->pluck('id'));
        $validatingGroup = \cache()->rememberForever('validating_group',
            fn () => Group::where('slug', '=', 'validating')->pluck('id'));
        $disabledGroup = \cache()->rememberForever('disabled_group',
            fn () => Group::where('slug', '=', 'disabled')->pluck('id'));

        // Check Passkey Against Users Table
        $user = User::with('group')
            ->select(['id', 'group_id', 'active', 'can_download', 'uploaded', 'downloaded'])
            ->where('passkey', '=', $passkey)
            ->first();

        // If User Doesn't Exist Return Error to Client
        \throw_if($user === null, new TrackerException(140));

        // If User Account Is Unactivated/Validating Return Error to Client
        \throw_if($user->active === 0 || $user->group->id === $validatingGroup[0],
            new TrackerException(141, [':status' => 'Unactivated/Validating']));

        // If User Download Rights Are Disabled Return Error to Client
        \throw_if($user->can_download === 0 && $queries['left'] !== '0',
            new TrackerException(142));

        // If User Is Banned Return Error to Client
        \throw_if($user->group->id === $bannedGroup[0],
            new TrackerException(141, [':status' => 'Banned']));

        // If User Is Disabled Return Error to Client
        throw_if($user->group->id === $disabledGroup[0],
            new TrackerException(141, [':status' => 'Disabled']));

        return $user;
    }

    /**
     * Check If Torrent Exist In Database.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    protected function checkTorrent($infoHash): object
    {
        // Check Info Hash Against Torrents Table
        $torrent = Torrent::query()
            ->select(['id', 'free', 'doubleup', 'seeders', 'leechers', 'times_completed', 'status'])
            ->withAnyStatus()
            ->where('info_hash', '=', $infoHash)
            ->first();

        // If Torrent Doesn't Exsist Return Error to Client
        \throw_if($torrent === null, new TrackerException(150));

        // If Torrent Is Pending Moderation Return Error to Client
        \throw_if($torrent->status === self::PENDING,
            new TrackerException(151, [':status' => 'PENDING In Moderation']));

        // If Torrent Is Rejected Return Error to Client
        \throw_if($torrent->status === self::REJECTED,
            new TrackerException(151, [':status' => 'REJECTED In Moderation']));

        // If Torrent Is Postponed Return Error to Client
        \throw_if($torrent->status === self::POSTPONED,
            new TrackerException(151, [':status' => 'POSTPONED In Moderation']));

        return $torrent;
    }

    /**
     * Check If Peer Exist In Database.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    private function checkPeer($torrent, $queries, $user): void
    {
        \throw_if(\strtolower($queries['event']) === 'completed' &&
            ! Peer::query()
                ->where('torrent_id', '=', $torrent->id)
                ->where('peer_id', $queries['peer_id'])
                ->where('user_id', '=', $user->id)
                ->exists(), new TrackerException(152));
    }

    /**
     * Check A Peers Min Annnounce Interval.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Exception
     * @throws \Throwable
     */
    private function checkMinInterval($torrent, $queries, $user): void
    {
        $prevAnnounce = Peer::query()
            ->select('updated_at')
            ->where('torrent_id', '=', $torrent->id)
            ->where('peer_id', '=', $queries['peer_id'])
            ->where('user_id', '=', $user->id)
            ->first();
        $setMin = \config('announce.min_interval.interval') ?? self::MIN;
        $randomMinInterval = random_int($setMin, $setMin * 2);
        \throw_if($prevAnnounce && $prevAnnounce->updated_at->greaterThan(now()->subSeconds($randomMinInterval))
            && \strtolower($queries['event']) !== 'completed' && \strtolower($queries['event']) !== 'stopped',
            new TrackerException(162, [':min' => $randomMinInterval]));
    }

    /**
     * Check A Users Max Connections.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    private function checkMaxConnections($torrent, $user): void
    {
        // Pull Count On Users Peers Per Torrent For Rate Limiting
        $connections = Peer::query()
            ->where('torrent_id', '=', $torrent->id)
            ->where('user_id', '=', $user->id)
            ->count();

        // If Users Peer Count On A Single Torrent Is Greater Than X Return Error to Client
        \throw_if($connections > \config('announce.rate_limit'),
            new TrackerException(138, [':limit' => \config('announce.rate_limit')]));
    }

    /**
     * Check A Users Download Slots.
     *
     * @throws \App\Exceptions\TrackerException
     * @throws \Throwable
     */
    private function checkDownloadSlots($queries, $user): void
    {
        $max = $user->group->download_slots;

        if ($max !== null && $max >= 0 && $queries['left'] != 0) {
            $count = Peer::query()
                ->where('user_id', '=', $user->id)
                ->where('peer_id', '!=', $queries['peer_id'])
                ->where('seeder', '=', 0)
                ->count();

            \throw_if($count >= $max,
                new TrackerException(164, [':max' => $max]));
        }
    }

    /**
     * Generate A Successful Announce Response For Client.
     *
     * @throws \Exception
     */
    private function generateSuccessAnnounceResponse($queries, $torrent, $user): array
    {
        // Build Response For Bittorrent Client
        $repDict = [
            'interval'     => random_int(self::MIN, self::MAX),
            'min interval' => self::MIN,
            'complete'     => (int) $torrent->seeders,
            'incomplete'   => (int) $torrent->leechers,
            'peers'        => '',
            'peers6'       => '',
        ];

        /**
         * For non `stopped` event only
         * We query peers from database and send peerlist, otherwise just quick return.
         */
        if (\strtolower($queries['event']) !== 'stopped') {
            $limit = (min($queries['numwant'], 25));

            // Get Torrents Peers (Only include leechers in a seeder's peerlist)
            $peers = Peer::query()
                ->where('torrent_id', '=', $torrent->id)
                ->when($queries['left'] == 0, fn ($query) => $query->where('seeder', '=', 0))
                ->where('user_id', '!=', $user->id)
                ->take($limit)
                ->get(['ip', 'port'])
                ->toArray();

            foreach ($peers as $peer) {
                if (isset($peer['ip'], $peer['port'])) {
                    $peer_insert_field = \filter_var($peer['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 'peers' : 'peers6';
                    $repDict[$peer_insert_field] .= \inet_pton($peer['ip']).\pack('n', (int) $peer['port']);
                }
            }
        }

        return $repDict;
    }

    /**
     * Process Announce Database Queries.
     */
    private function processAnnounceJob($queries, $user, $torrent): void
    {
        ProcessAnnounce::dispatch($queries, $user, $torrent);
    }

    protected function generateFailedAnnounceResponse(TrackerException $trackerException): array
    {
        return [
            'failure reason' => $trackerException->getMessage(),
            'min interval'   => self::MIN,
        ];
    }

    /**
     * Send Final Announce Response.
     */
    protected function sendFinalAnnounceResponse($repDict): Response
    {
        return \response(Bencode::bencode($repDict))
            ->withHeaders(['Content-Type' => 'text/plain; charset=utf-8'])
            ->withHeaders(['Connection' => 'close'])
            ->withHeaders(['Pragma' => 'no-cache']);
    }
}
