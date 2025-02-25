<?php

namespace App\Http\Controllers\Impersonate;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Lab404\Impersonate\Controllers\ImpersonateController;
use Lab404\Impersonate\Services\ImpersonateManager;

class MyImpersonateController extends ImpersonateController
{
    /**
     * @param int         $id
     * @param string|null $guardName
     * @return  RedirectResponse
     * @throws  \Exception
     */
    public function leaveAndTake(Request $request, $id, $guardName = null)
    {
        if (!$this->manager->isImpersonating()) {
            abort(403);
        }

        $this->manager->leave();
        return $this->take($request, $id, $guardName);
    }
}

