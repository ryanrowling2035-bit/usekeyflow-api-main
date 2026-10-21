<?php

namespace App\Services;

use App\Constants\Constants;
use App\Exceptions\UserFacingException;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserMeta;
use App\Repositories\UserRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class UserService
{
    public function __construct(
        protected UserRepository $userRepository,
        protected FileStorageService $fileStorage
    ) {}

    // ================= listUsers =================
    public function listUsers(Request $request)
    {
        return $this->userRepository->getAll($request);
    }

    // ================= createUser =================
    public function createUser(array $data, Request $request)
    {

        if ($request->hasFile('profilePhoto')) {
            $file = $request->file('profilePhoto');
            $originalName = time().'_'.str_replace(' ', '', $file->getClientOriginalName());
            $data['profilePhoto'] = $this->fileStorage->uploadPublicFile($file, 'users', $originalName);
            $data['thumbnailPath'] = $this->generateUserThumbnail($file->getPathname(), $originalName);
        }

        $data['password'] = Hash::make($data['password']);

        // Set createdBy to current authenticated user
        if (! isset($data['createdBy'])) {
            $data['createdBy'] = user() ? user()->id : null;
        }

        $data = normalizePhoneFields($data, ['phone']);

        $user = $this->userRepository->create($data);
        $this->syncUserMeta($user->id, $request);

        return $user;
    }

    // ================= assignAllPermissionsToRole =================
    protected function assignAllPermissionsToRole(int $roleId): void
    {
        $role = Role::find($roleId);

        if (! $role) {
            return;
        }

        $allPermissionIds = Permission::pluck('id')->toArray();

        if (! empty($allPermissionIds)) {
            $role->permissions()->sync($allPermissionIds);
        }
    }

    protected function syncUserMeta(int $userId, Request $request): void
    {
        if ($request->hasFile('idCard')) {

            $idCards = [];

            foreach ($request->file('idCard') as $file) {
                $originalName = time().'_'.str_replace(' ', '', $file->getClientOriginalName());
                // ID cards are confidential — stored in the private bucket; only the key is kept.
                $key = $this->fileStorage->uploadPrivateFile($file, 'documents/id-cards', $originalName);

                $idCards[] = [
                    'fileName' => $originalName,
                    'filePath' => $key,
                    'type' => $file->getClientMimeType(),
                ];
            }

            UserMeta::updateOrCreate(
                [
                    'userId' => $userId,
                    'metaKey' => Constants::USER_META_KEYS['idCard'],
                ],
                [
                    'metaValue' => json_encode($idCards),
                ]
            );
        }

        foreach (Constants::USER_META_KEYS as $requestKey => $metaKey) {
            if ($requestKey === 'idCard') {
                continue;
            }

            if ($request->has($requestKey)) {

                // Only one user can be defaultAdmin at a time
                if ($requestKey === 'defaultAdmin' && $request->input($requestKey)) {
                    $existingAdmin = UserMeta::where('metaKey', $metaKey)
                        ->where('metaValue', '1')
                        ->where('userId', '!=', $userId)
                        ->with('user:id,name')
                        ->first();

                    if ($existingAdmin) {
                        throw new UserFacingException("\"{$existingAdmin->user?->name}\" is already set as the default admin. Please remove them first before assigning a new one.");
                    }
                }

                UserMeta::updateOrCreate(
                    [
                        'userId' => $userId,
                        'metaKey' => $metaKey,
                    ],
                    [
                        'metaValue' => $request->input($requestKey),
                    ]
                );
            }
        }
    }

    // ================= updateUser =================
    public function updateUser(int $id, array $data, Request $request)
    {
        $user = $this->userRepository->findById($id);

        if (! $user) {
            throw new UserFacingException('User not found');
        }

        // Handle profile photo removal
        if (! empty($data['removeProfilePhoto'])) {
            $this->fileStorage->deletePublicByUrl($data['removeProfilePhoto']);
            $this->fileStorage->deletePublicByUrl($user->thumbnailPath);
            $data['profilePhoto'] = null;
            $data['thumbnailPath'] = null;
            unset($data['removeProfilePhoto']);
        }

        if ($request->hasFile('profilePhoto')) {
            $this->fileStorage->deletePublicByUrl($user->profilePhoto);
            $this->fileStorage->deletePublicByUrl($user->thumbnailPath);

            $file = $request->file('profilePhoto');
            $originalName = time().'_'.str_replace(' ', '', $file->getClientOriginalName());
            $data['profilePhoto'] = $this->fileStorage->uploadPublicFile($file, 'users', $originalName);
            $data['thumbnailPath'] = $this->generateUserThumbnail($file->getPathname(), $originalName);
        }

        if (! empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
            $data['force_password_change'] = false;
        } else {
            unset($data['password']);
        }

        $data = normalizePhoneFields($data, ['phone']);

        $this->userRepository->update($user, $data);

        $this->syncUserMeta($user->id, $request);

        return $user->fresh();
    }

    // ================= deleteUser =================
    public function deleteUser(User $user)
    {
        if ($user->id === 1) {
            throw new UserFacingException('Super Admin cannot be deleted');
        }

        return $this->userRepository->delete($user);
    }

    // ================= updateUserStatus =================
    public function updateUserStatus(User $user, string $status)
    {
        $this->userRepository->update($user, ['status' => $status]);

        return $user->fresh();
    }

    protected function generateUserThumbnail(string $sourcePath, string $fileName): string
    {
        $manager = new ImageManager(new Driver);
        $thumbnail = $manager->read($sourcePath);
        $thumbnail->cover(150, 150);

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $encoded = match ($extension) {
            'png' => $thumbnail->toPng(),
            'webp' => $thumbnail->toWebp(75),
            default => $thumbnail->toJpeg(75),
        };

        return $this->fileStorage->putPublic('users/thumbnails/'.$fileName, (string) $encoded);
    }
}
