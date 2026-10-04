<?php

namespace App\Modules\P2P\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Modules\P2P\Models\PeerTrade\PeerOrder;
use App\Modules\P2P\Models\PeerTrade\PeerOrderAppeal;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrderRepository;
use App\Modules\P2P\Repositories\PeerTrade\PeerOrdersAppealsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Setting;

class PeerOrdersAppealsController extends Controller
{
    /**
     * @var PeerOrdersAppealsRepository
     */
    protected $peerOrdersAppealsRepository;

    /**
     * @param PeerOrdersAppealsRepository $peerOrdersAppealsRepository
     *
     */
    public function __construct(PeerOrdersAppealsRepository $peerOrdersAppealsRepository)
    {
        $this->peerOrdersAppealsRepository = $peerOrdersAppealsRepository;
    }


    public function index()
    {
        $appeals = $this->peerOrdersAppealsRepository->list(true, true);

        return Inertia::render('Admin/PeerTrades/OrdersAppeals', [
            'appeals' => $appeals,
        ]);
    }

    /**
     * View resource.
     *
     * @param PeerOrderAppeal $appeal
     * @return \Inertia\Response
     */
    public function view(PeerOrderAppeal $appeal)
    {
        $appeal = $this->peerOrdersAppealsRepository->getAppealById($appeal->id, true, []);

        return Inertia::render('Admin/PeerTrades/OrdersAppealView', [
            'isEdit' => true,
            'appeal' => $appeal,
        ]);
    }

    public function getChat(Request $request) {

        $data = $request->validate(['order_id' => ['required', 'exists:peer_orders,id']]);
        $order = PeerOrder::findOrFail($data['order_id']);
        $peerOrderRepository = new PeerOrderRepository();
        $messages = $peerOrderRepository->getMessages($order, $request->user()->id, true);

        return response()->json([
            'messages' => $messages
        ]);
    }


    public function postChat(Request $request) {

        $post = $request->validate(['message' => ['required','string','max:5000'], 'order_id' => ['required','exists:peer_orders,id']]);

        $user = $request->user();

        $order = PeerOrder::where('id', $post['order_id'])->first();

        $post['is_author'] = $order->user_id == $user->id;
        $post['user_id'] = $user->id;

        $peerOrderRepository = new PeerOrderRepository();
        $peerOrderRepository->storeMessage($post);

        return response()->json([
            'status' => true
        ]);
    }

    public function moderate(Request $request) {

        $id = $request->get('id');
        $type = $request->get('status');

        $result = $this->peerOrdersAppealsRepository->moderate($id, $type);

        return response()->json([
            'status' => (bool)$result
        ]);

    }

}
