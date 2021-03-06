<?php

namespace App\Repositories;

use App\Models\AudioCodec;
use App\Models\Category;
use App\Models\Codec;
use App\Models\Media;
use App\Models\Peer;
use App\Models\Processing;
use App\Models\Snatch;
use App\Models\Source;
use App\Models\Standard;
use App\Models\Team;
use App\Models\Torrent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Http\Request;

class TorrentRepository extends BaseRepository
{
    /**
     *  fetch torrent list
     *
     * @param array $params
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getList(array $params)
    {
        $query = Torrent::query();
        if (!empty($params['category'])) {
            $query->where('category', $params['category']);
        }
        if (!empty($params['source'])) {
            $query->where('source', $params['source']);
        }
        if (!empty($params['medium'])) {
            $query->where('medium', $params['medium']);
        }
        if (!empty($params['codec'])) {
            $query->where('codec', $params['codec']);
        }
        if (!empty($params['audio_codec'])) {
            $query->where('audiocodec', $params['audio_codec']);
        }
        if (!empty($params['standard'])) {
            $query->where('standard', $params['standard']);
        }
        if (!empty($params['processing'])) {
            $query->where('processing', $params['processing']);
        }
        if (!empty($params['team'])) {
            $query->where('team', $params['team']);
        }
        if (!empty($params['owner'])) {
            $query->where('owner', $params['owner']);
        }
        if (!empty($params['visible'])) {
            $query->where('visible', $params['visible']);
        }

        if (!empty($params['query'])) {
            $query->where(function (Builder $query) use ($params) {
                $query->where('name', 'like', "%{$params['query']}%")
                    ->orWhere('small_descr', 'like', "%{$params['query']}%");
            });
        }

        list($sortField, $sortType) = $this->getSortFieldAndType($params);
        $query->orderBy($sortField, $sortType);

        $with = ['user'];
        $torrents = $query->with($with)->paginate();
        return $torrents;
    }

    public function getSearchBox()
    {
        $category = Category::query()->orderBy('sort_index')->orderBy('id')->get();
        $source = Source::query()->orderBy('sort_index')->orderBy('id')->get();
        $media = Media::query()->orderBy('sort_index')->orderBy('id')->get();
        $codec = Codec::query()->orderBy('sort_index')->orderBy('id')->get();
        $standard = Standard::query()->orderBy('sort_index')->orderBy('id')->get();
        $processing = Processing::query()->orderBy('sort_index')->orderBy('id')->get();
        $team = Team::query()->orderBy('sort_index')->orderBy('id')->get();
        $audioCodec = AudioCodec::query()->orderBy('sort_index')->orderBy('id')->get();

        $modalRows = [];
        $modalRows[] = $categoryFormatted = $this->formatRow('类型', $category, 'category');
        $modalRows[] = $this->formatRow('媒介', $source, 'source');
        $modalRows[] = $this->formatRow('媒介', $media, 'medium');
        $modalRows[] = $this->formatRow('编码', $codec, 'codec');
        $modalRows[] = $this->formatRow('音频编码', $audioCodec, 'audio_codec');
        $modalRows[] = $this->formatRow('分辨率', $standard, 'standard');
        $modalRows[] = $this->formatRow('处理', $processing, 'processing');
        $modalRows[] = $this->formatRow('制作组', $team, 'team');

        $results = [];
        $categories = $categoryFormatted['rows'];
        $categories[0]['active'] = 1;
        $results['categories'] = $categories;
        $results['modal_rows'] = $modalRows;


        return $results;
    }

    private function formatRow($header, $items, $name)
    {
        $result['header'] = $header;
        $result['rows'][] = [
            'label' => '全部',
            'value' => 0,
            'name' => $name,
            'active' => 1,
        ];
        foreach ($items as $value) {
            $item = [
                'label' => $value->name,
                'value' => $value->id,
                'name' => $name,
                'active' => 0,
            ];
            $result['rows'][] = $item;
        }
        return $result;
    }

    public function listPeers($torrentId)
    {
        $seederList = $leecherList = collect();
        $peers = Peer::query()->where('torrent', $torrentId)->with(['user', 'relative_torrent'])->get()->groupBy('seeder');
        if ($peers->has(Peer::SEEDER_YES)) {
            $seederList = $peers->get(Peer::SEEDER_YES)->sort(function ($a, $b) {
                $x = $a->uploaded;
                $y = $b->uploaded;
                if ($x == $y)
                    return 0;
                if ($x < $y)
                    return 1;
                return -1;
            });
            $seederList = $this->formatPeers($seederList);
        }
        if ($peers->has(Peer::SEEDER_NO)) {
            $leecherList = $peers->get(Peer::SEEDER_NO)->sort(function ($a, $b) {
                $x = $a->to_go;
                $y = $b->to_go;
                if ($x == $y)
                    return 0;
                if ($x < $y)
                    return -1;
                return 1;
            });
            $leecherList = $this->formatPeers($leecherList);
        }

        return [
            'seeder_list' => $seederList,
            'leecher_list' => $leecherList,
        ];

    }

    public function getPeerUploadSpeed($peer): string
    {
        $diff = $peer->uploaded - $peer->uploadoffset;
        $seconds = max(1, $peer->started->diffInSeconds($peer->last_action));
        return mksize($diff / $seconds) . '/s';
    }

    public function getPeerDownloadSpeed($peer): string
    {
        $diff = $peer->downloaded - $peer->downloadoffset;
        if ($peer->isSeeder()) {
            $seconds = max(1, $peer->started->diffInSeconds($peer->finishedat));
        } else {
            $seconds = max(1, $peer->started->diffInSeconds($peer->last_action));
        }
        return mksize($diff / $seconds) . '/s';
    }

    public function getDownloadProgress($peer): string
    {
        return sprintf("%.2f%%", 100 * (1 - ($peer->to_go / $peer->relative_torrent->size)));
    }

    public function getShareRatio($peer)
    {
        if ($peer->downloaded) {
            $ratio = floor(($peer->uploaded / $peer->downloaded) * 1000) / 1000;
        } elseif ($peer->uploaded) {
            //@todo 读语言文件
            $ratio = '无限';
        } else {
            $ratio = '---';
        }
        return $ratio;
    }

    private function formatPeers($peers)
    {
        foreach ($peers as &$item) {
            $item->upload_text = sprintf('%s@%s', mksize($item->uploaded), $this->getPeerUploadSpeed($item));
            $item->download_text = sprintf('%s@%s', mksize($item->downloaded), $this->getPeerDownloadSpeed($item));
            $item->download_progress = $this->getDownloadProgress($item);
            $item->share_ratio = $this->getShareRatio($item);
            $item->connect_time_total = $item->started->diffForHumans();
            $item->last_action_human = $item->last_action->diffForHumans();
            $item->agent_human = htmlspecialchars(get_agent($item->peer_id, $item->agent));
        }
        return $peers;
    }


    public function listSnatches($torrentId)
    {
        $snatches = Snatch::query()
            ->where('torrentid', $torrentId)
            ->where('finished', Snatch::FINISHED_YES)
            ->with(['user'])
            ->orderBy('completedat', 'desc')
            ->paginate();
        foreach ($snatches as &$snatch) {
            $snatch->upload_text = sprintf('%s@%s', mksize($snatch->uploaded), $this->getSnatchUploadSpeed($snatch));
            $snatch->download_text = sprintf('%s@%s', mksize($snatch->uploaded), $this->getSnatchDownloadSpeed($snatch));
            $snatch->share_ratio = $this->getShareRatio($snatch);
            $snatch->seed_time = mkprettytime($snatch->seedtime);
            $snatch->leech_time = mkprettytime($snatch->leechtime);
            $snatch->completed_at_human = $snatch->completedat->diffForHumans();
            $snatch->last_action_human =  $snatch->last_action->diffForHumans();
        }
        return $snatches;
    }

    public function getSnatchUploadSpeed($snatch)
    {
        if ($snatch->seedtime <= 0) {
            $speed =  mksize(0);
        } else {
            $speed = mksize($snatch->uploaded / ($snatch->seedtime + $snatch->leechtime));
        }
        return "$speed/s";
    }

    public function getSnatchDownloadSpeed($snatch)
    {
        if ($snatch->leechtime <= 0) {
            $speed = mksize(0);
        } else {
            $speed = mksize($snatch->downloaded / $snatch->leechtime);
        }
        return "$speed/s";
    }

}
