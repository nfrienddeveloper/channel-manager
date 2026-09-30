<?php

namespace App\Http\Controllers;

use App\Models\PlatformAccount;
use App\Services\Platforms\Facebook\PageConnector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AccountController extends Controller
{
    public function index()
    {
        return view('accounts.index', ['accounts' => PlatformAccount::withCount('channels')->orderBy('platform')->get()]);
    }

    /** Step 1: look up the Pages this token can post to. The token only lives in the encrypted session until step 2. */
    public function facebookPages(Request $request, PageConnector $connector)
    {
        $v = $request->validate(['user_token' => 'required|string', 'app_id' => 'nullable|string', 'app_secret' => 'nullable|string']);
        try {
            $pages = $connector->pages($v['user_token'], $v['app_id'] ?: null, $v['app_secret'] ?: null);
        } catch (Throwable $e) {
            return back()->withErrors(['facebook' => $e->getMessage()]);
        }
        if (! $pages) {
            return back()->withErrors(['facebook' => 'That token cannot post to any Page. Check the permissions and that you picked the Page when approving.']);
        }
        session(['fb_pages' => Crypt::encrypt(['pages' => $pages, 'long_lived' => (bool) (($v['app_id'] ?? null) && ($v['app_secret'] ?? null)) || (config('channels.facebook.app_id') && config('channels.facebook.app_secret'))])]);

        return view('accounts.facebook-pages', ['pages' => collect($pages)->map(fn ($p) => ['id' => $p['id'], 'name' => $p['name']])]);
    }

    public function facebookStore(Request $request, PageConnector $connector)
    {
        $id = $request->validate(['page_id' => 'required|string'])['page_id'];
        $data = Crypt::decrypt(session()->pull('fb_pages', Crypt::encrypt(['pages' => [], 'long_lived' => false])));
        $page = collect($data['pages'])->firstWhere('id', $id);
        abort_unless($page, 422, 'Page not found; connect again.');
        $account = $connector->store($page, $data['long_lived']);

        return redirect()->route('accounts.index')->with('status', "Connected {$account->name}.".($data['long_lived'] ? '' : ' Without the app secret the token expires in about an hour.'));
    }

    public function destroy(PlatformAccount $account)
    {
        $account->channels()->update(['live' => false, 'platform_account_id' => null]);
        $account->delete();

        return back()->with('status', 'Account removed. Channels that used it are back in dry run.');
    }
}
