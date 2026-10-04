<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\User\User;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\Request;

class SumsubController extends Controller
{
    #[ExcludeRouteFromDocs]
    public function ipn(Request $request)
    {
        $return = file_get_contents('php://input');

        $algoStr = $request->header('x-payload-digest-alg');
        $digest = $request->header('x-payload-digest');

        $algo = match($algoStr) {
            'HMAC_SHA1_HEX' => 'sha1',
            'HMAC_SHA256_HEX' => 'sha256',
            'HMAC_SHA512_HEX' => 'sha512',
            default => throw new \RuntimeException('Unsupported algorithm'),
        };

        $res = $digest === hash_hmac(
                $algo,
                $return,
                config('sumsub.webhook_secret')
            );

        if(!$res) {
            return response()->json([
                'status' => 'error'
            ])->setStatusCode('422');
        }

        $applicantId = $request->get('applicantId');
        $correlationId = $request->get('correlationId');
        $userId = $request->get('externalUserId');
        $type = $request->get('type');
        $reviewStatus = $request->get('reviewStatus');
        $reviewResult = $request->get('reviewResult');
        $applicationType = $request->get('applicantType');
        $date = $request->get('createdAt');

        $user = User::where('referral_code', $userId)->first();

        if(!$user) {
            return response()->json([
                'status' => 'error'
            ])->setStatusCode('422');
        }

        if($type == "applicantPending") {

            $user->sumsub_applicant_id = $applicantId;
            $user->sumsub_colleration_id = $correlationId;
            $user->sumsub_review_status = $reviewStatus;
            $user->sumsub_account_type = $applicationType;
            $user->update();

            return response()->json([
               'status' => 'ok'
            ]);
        }

        if($type == "applicantReviewed") {

            $isApproved = $reviewResult['reviewAnswer'] == "GREEN";

            $user->sumsub_applicant_id = $applicantId;
            $user->sumsub_colleration_id = $correlationId;
            $user->sumsub_review_status = $reviewStatus;
            $user->sumsub_applicant_status = $isApproved ? 'approved' : 'rejected';
            $user->kyc_verified_at = $isApproved ? now() : null;
            $user->update();

            return response()->json([
                'status' => 'ok'
            ]);
        }
    }
}
