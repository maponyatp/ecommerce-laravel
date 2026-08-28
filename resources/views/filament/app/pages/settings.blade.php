<x-filament-panels::page>
    <form wire:submit.prevent="save" class="space-y-6">
        <!-- Site Information Section -->
        <div class="p-6 bg-white rounded-none border border-zinc-200 shadow-sm dark:bg-gray-900 dark:border-gray-850">
            <h3 class="text-sm font-bold text-black dark:text-white uppercase tracking-wider mb-6 pb-2 border-b border-zinc-100">Site Information</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Site Name -->
                <div>
                    <label for="site_name" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Site Name</label>
                    <input type="text" wire:model="site_name" id="site_name" required class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>

                <!-- Site Email -->
                <div>
                    <label for="site_email" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Site Email</label>
                    <input type="email" wire:model="site_email" id="site_email" required class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>

                <!-- Site Phone -->
                <div>
                    <label for="site_phone" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Site Phone</label>
                    <input type="text" wire:model="site_phone" id="site_phone" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>

                <!-- Site Currency -->
                <div>
                    <label for="site_currency" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Currency Symbol</label>
                    <input type="text" wire:model="site_currency" id="site_currency" required class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>

                <!-- Site Address -->
                <div class="md:col-span-2">
                    <label for="site_address" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Site Address</label>
                    <input type="text" wire:model="site_address" id="site_address" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>
            </div>
        </div>

        <!-- Social Media Links Section -->
        <div class="p-6 bg-white rounded-none border border-zinc-200 shadow-sm dark:bg-gray-900 dark:border-gray-850">
            <h3 class="text-sm font-bold text-black dark:text-white uppercase tracking-wider mb-6 pb-2 border-b border-zinc-100">Social Media profiles</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Facebook -->
                <div>
                    <label for="facebook_url" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Facebook URL</label>
                    <input type="url" wire:model="facebook_url" id="facebook_url" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>

                <!-- Twitter -->
                <div>
                    <label for="twitter_url" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Twitter URL</label>
                    <input type="url" wire:model="twitter_url" id="twitter_url" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>

                <!-- Youtube -->
                <div>
                    <label for="youtube_url" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">YouTube URL</label>
                    <input type="url" wire:model="youtube_url" id="youtube_url" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black">
                </div>
            </div>
        </div>

        <!-- Footer Section -->
        <div class="p-6 bg-white rounded-none border border-zinc-200 shadow-sm dark:bg-gray-900 dark:border-gray-850">
            <h3 class="text-sm font-bold text-black dark:text-white uppercase tracking-wider mb-6 pb-2 border-b border-zinc-100">Footer Options</h3>
            <div>
                <label for="footer_copyright" class="block text-xs font-bold text-zinc-500 uppercase tracking-wider mb-2">Copyright Wording</label>
                <textarea wire:model="footer_copyright" id="footer_copyright" required rows="3" class="w-full rounded-none border-zinc-300 dark:border-zinc-700 dark:bg-gray-800 text-sm text-black dark:text-white focus:ring-black focus:border-black"></textarea>
            </div>
        </div>

        <!-- Save Button -->
        <div class="flex justify-end">
            <button type="submit" class="bg-black text-white px-8 py-3 text-xs font-bold tracking-widest uppercase hover:bg-zinc-800 transition-colors">
                Save Settings
            </button>
        </div>
    </form>
</x-filament-panels::page>
