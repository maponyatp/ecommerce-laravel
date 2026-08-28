<?php

namespace App\Filament\Admin\Resources\Pages;

use App\Filament\Admin\Resources\Pages\Pages\CreatePage;
use App\Filament\Admin\Resources\Pages\Pages\EditPage;
use App\Filament\Admin\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Page content')->description('Save a draft first. Preview and publish it separately; saving never replaces the live page.')->columnSpanFull()
                    ->schema([
                        TextInput::make('editor_version')->label('Saved revision')->default(0)->disabled()->dehydrated()->required()->integer()
                            ->helperText('Reload if another editor has changed this page.'),
                        TextInput::make('title')
                            ->required()
                            ->maxLength(191)->live(onBlur: true)
                            ->afterStateUpdated(function (?Page $record, Get $get, Set $set, ?string $state): void {
                                if (! $record && blank($get('slug'))) {
                                    $set('slug', Str::slug($state ?? ''));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(191)->rules(['regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'])
                            ->disabled(fn (?Page $record) => $record !== null)
                            ->helperText('Lower-case words separated by hyphens. The URL is locked after creation to protect menu links.'),
                        RichEditor::make('content')
                            ->toolbarButtons([['bold', 'italic', 'underline', 'strike', 'link'], ['h2', 'h3', 'blockquote', 'bulletList', 'orderedList'], ['table', 'attachFiles'], ['undo', 'redo']])
                            ->fileAttachmentsDisk('public')->fileAttachmentsDirectory('cms/page-content')
                            ->helperText('Text, links and images are supported. Scripts, forms, embeds and unsafe styling are removed. Uploaded images are public files, even before publication.')
                            ->columnSpanFull(),
                        TextInput::make('meta_title')
                            ->maxLength(255),
                        Textarea::make('meta_description')
                            ->maxLength(500),
                        FileUpload::make('featured_image')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/gif', 'image/webp'])->maxSize(2048)
                            ->disk('public')
                            ->directory('cms/pages')
                            ->visibility('public')->helperText('Optional public image. Do not upload confidential files to a draft.'),
                    ]),
                Section::make('Publishing & revision history')->visible(fn (?Page $record) => $record !== null)
                    ->schema([View::make('filament.admin.pages.cms-revisions')])->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->state(fn (Page $record) => $record->draft_data['title'] ?? $record->title)
                    ->searchable(query: fn ($query, string $search) => $query->where(fn ($match) => $match->where('title', 'like', '%'.$search.'%')->orWhere('draft_data->title', 'like', '%'.$search.'%'))),
                TextColumn::make('slug')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Publication')->formatStateUsing(fn (Page $record) => $record->publicationLabel())->badge(),
                TextColumn::make('editor_version')->label('Revision'),
                TextColumn::make('published_at')->label('Last published')->dateTime()->placeholder('Not recorded'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Create your first store page')
            ->emptyStateDescription('Write a draft, review its preview, then publish when ready.');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPages::route('/'),
            'create' => CreatePage::route('/create'),
            'edit' => EditPage::route('/{record}/edit'),
        ];
    }
}
