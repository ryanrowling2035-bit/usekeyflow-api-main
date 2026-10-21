<ul style="list-style-type: none; padding: 0;">
    @if(!empty($photos))
        <li><strong>Photos ({{ count($photos) }}):</strong>
            <ul>
                @foreach($photos as $photo)
                    <li>&#8226; <a href="{{ $photo['url'] }}" download="{{ $photo['name'] }}" style="color: #1a73e8; text-decoration: none;">{{ $photo['name'] }}</a></li>
                @endforeach
            </ul>
        </li>
    @endif
    @if(!empty($videos))
        <li><strong>Videos ({{ count($videos) }}):</strong>
            <ul>
                @foreach($videos as $video)
                    <li>&#8226; <a href="{{ $video['url'] }}" download="{{ $video['name'] }}" style="color: #1a73e8; text-decoration: none;">{{ $video['name'] }}</a></li>
                @endforeach
            </ul>
        </li>
    @endif
    @if(!empty($floorPlans))
        <li><strong>Floor Plans ({{ count($floorPlans) }}):</strong>
            <ul>
                @foreach($floorPlans as $plan)
                    <li>&#8226; <a href="{{ $plan['url'] }}" download="{{ $plan['name'] }}" style="color: #1a73e8; text-decoration: none;">{{ $plan['name'] }}</a></li>
                @endforeach
            </ul>
        </li>
    @endif
    @if(!empty($documents))
        <li><strong>Documents ({{ count($documents) }}):</strong>
            <ul>
                @foreach($documents as $doc)
                    <li>&#8226; <a href="{{ $doc['url'] }}" download="{{ $doc['name'] }}" style="color: #1a73e8; text-decoration: none;">{{ $doc['name'] }}</a></li>
                @endforeach
            </ul>
        </li>
    @endif
</ul>
